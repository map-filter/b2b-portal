<?php

namespace App\Http\Controllers\Api\Manager;

use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\PriceType;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CompanyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate(['q' => ['nullable', 'string', 'max:100'], 'mine' => ['nullable', 'boolean']]);

        $companies = Company::query()
            ->with(['manager', 'defaultContract.priceType'])
            ->withCount('users')
            ->withCount(['orders as open_orders' => fn ($q) => $q->whereNotIn('status', [OrderStatus::Completed, OrderStatus::Cancelled])])
            ->withSum(['orders as revenue' => fn ($q) => $q->where('status', '!=', OrderStatus::Cancelled)], 'total')
            ->withMax('orders as last_order_at', 'created_at')
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'ilike', '%'.$request->string('q').'%')
                ->orWhere('bin', 'like', '%'.$request->string('q').'%')))
            ->when($request->boolean('mine'), fn ($q) => $q->where('manager_id', $request->user()->id))
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $companies->map(fn (Company $company) => $this->present($company))]);
    }

    public function show(Company $company): JsonResponse
    {
        $company->load(['manager', 'defaultContract.priceType', 'users'])
            ->loadCount(['orders as open_orders' => fn ($q) => $q->whereNotIn('status', [OrderStatus::Completed, OrderStatus::Cancelled])])
            ->loadSum(['orders as revenue' => fn ($q) => $q->where('status', '!=', OrderStatus::Cancelled)], 'total')
            ->loadSum(['orders as open_total' => fn ($q) => $q->whereNotIn('status', [OrderStatus::Completed, OrderStatus::Cancelled])], 'total')
            ->loadMax('orders as last_order_at', 'created_at');

        return response()->json(['data' => $this->present($company) + [
            'open_total' => number_format((float) $company->open_total, 2, '.', ''),
            'users' => $company->users->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'role' => $user->role->value,
                'role_label' => $user->role->label(),
                'is_active' => $user->is_active,
            ]),
            'price_types' => PriceType::orderBy('id')->get(['id', 'name', 'code']),
            'managers' => User::whereIn('role', [UserRole::Manager, UserRole::Admin])->orderBy('name')->get(['id', 'name']),
        ]]);
    }

    public function update(Request $request, Company $company): JsonResponse
    {
        $data = $request->validate([
            'manager_id' => ['nullable', Rule::exists('users', 'id')->whereIn('role', [UserRole::Manager->value, UserRole::Admin->value])],
            'credit_limit' => ['required', 'numeric', 'min:0', 'max:999999999'],
            'payment_delay_days' => ['required', 'integer', 'min:0', 'max:365'],
            'is_active' => ['required', 'boolean'],
            'contract.price_type_id' => ['required', 'exists:price_types,id'],
            'contract.discount_percent' => ['required', 'numeric', 'min:0', 'max:50'],
            'contract.ends_at' => ['nullable', 'date'],
        ]);

        $company->update(collect($data)->except('contract')->all());
        $company->defaultContract?->update($data['contract']);

        return $this->show($company);
    }

    public function storeUser(Request $request, Company $company): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:32'],
            'role' => ['required', Rule::in([UserRole::ClientAdmin->value, UserRole::ClientBuyer->value])],
        ]);

        $password = Str::password(12, symbols: false);
        $user = $company->users()->create($data + ['password' => $password]);

        return response()->json(['data' => ['id' => $user->id, 'email' => $user->email, 'password' => $password]], 201);
    }

    public function toggleUser(Company $company, User $user): JsonResponse
    {
        abort_unless($user->company_id === $company->id, 404);

        $user->update(['is_active' => ! $user->is_active]);

        if (! $user->is_active) {
            $user->tokens()->delete();
        }

        return response()->json(['data' => ['id' => $user->id, 'is_active' => $user->is_active]]);
    }

    private function present(Company $company): array
    {
        $contract = $company->defaultContract;

        return [
            'id' => $company->id,
            'name' => $company->name,
            'legal_name' => $company->legal_name,
            'bin' => $company->bin,
            'legal_address' => $company->legal_address,
            'phone' => $company->phone,
            'email' => $company->email,
            'is_active' => $company->is_active,
            'credit_limit' => $company->credit_limit,
            'payment_delay_days' => $company->payment_delay_days,
            'manager' => $company->manager ? ['id' => $company->manager->id, 'name' => $company->manager->name] : null,
            'contract' => $contract ? [
                'id' => $contract->id,
                'number' => $contract->number,
                'price_type_id' => $contract->price_type_id,
                'price_type' => $contract->priceType->name,
                'discount_percent' => $contract->discount_percent,
                'starts_at' => $contract->starts_at->toDateString(),
                'ends_at' => $contract->ends_at?->toDateString(),
            ] : null,
            'users_count' => $company->users_count ?? null,
            'open_orders' => (int) $company->open_orders,
            'revenue' => number_format((float) $company->revenue, 2, '.', ''),
            'last_order_at' => $company->last_order_at ? Carbon::parse($company->last_order_at)->toIso8601String() : null,
        ];
    }
}
