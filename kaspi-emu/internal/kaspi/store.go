package kaspi

import (
	"encoding/json"
	"errors"
	"fmt"
	"log/slog"
	"math/rand/v2"
	"os"
	"path/filepath"
	"slices"
	"strconv"
	"sync"
	"time"
)

type Config struct {
	Token          string
	PublicURL      string
	StateFile      string
	MaxRangeDays   int
	SignAfter      time.Duration
	CourierAfter   time.Duration
	AcceptTimeout  time.Duration
	Location       *time.Location
	Seed           uint64
	HistoryDays    int
	OrdersPerDay   int
	DefaultCompany string
}

type APIError struct {
	Status int
	Title  string
}

func (e *APIError) Error() string { return e.Title }

func badRequest(format string, args ...any) *APIError {
	return &APIError{Status: 400, Title: fmt.Sprintf(format, args...)}
}

type PriceListInfo struct {
	Source   string    `json:"source"`
	LoadedAt time.Time `json:"loadedAt"`
	Company  string    `json:"company"`
	Merchant string    `json:"merchantId"`
	Offers   int       `json:"offers"`
	InStock  int       `json:"inStock"`
	Error    string    `json:"error,omitempty"`
	FailedAt time.Time `json:"failedAt,omitempty"`
}

type state struct {
	Seq       int64            `json:"seq"`
	Orders    []*Order         `json:"orders"`
	Offers    []Offer          `json:"offers"`
	PriceList PriceListInfo    `json:"priceList"`
	Seeded    bool             `json:"seeded"`
	Products  map[string]Entry `json:"products"`
}

type Store struct {
	cfg  Config
	mu   sync.Mutex
	st   state
	rnd  *rand.Rand
	now  func() time.Time
	byID map[string]*Order
}

func NewStore(cfg Config) *Store {
	if cfg.Location == nil {
		cfg.Location = time.UTC
	}
	if cfg.Seed == 0 {
		cfg.Seed = 2026
	}
	s := &Store{cfg: cfg, now: time.Now, rnd: rand.New(rand.NewPCG(cfg.Seed, 7))}
	s.st.Seq = 550688000
	s.st.Products = map[string]Entry{}
	s.load()
	return s
}

func (s *Store) SetClock(now func() time.Time) { s.now = now }

func (s *Store) load() {
	defer s.reindex()
	if s.cfg.StateFile == "" {
		return
	}
	data, err := os.ReadFile(s.cfg.StateFile)
	if err != nil {
		return
	}
	var st state
	if err := json.Unmarshal(data, &st); err != nil {
		slog.Error("файл состояния повреждён, начинаю с нуля", "err", err)
		return
	}
	if st.Products == nil {
		st.Products = map[string]Entry{}
	}
	s.st = st
}

func (s *Store) reindex() {
	s.byID = make(map[string]*Order, len(s.st.Orders))
	for _, o := range s.st.Orders {
		s.byID[o.ID] = o
	}
}

func (s *Store) save() {
	if s.cfg.StateFile == "" {
		return
	}
	data, err := json.Marshal(s.st)
	if err != nil {
		slog.Error("сохранение состояния", "err", err)
		return
	}
	tmp := s.cfg.StateFile + ".tmp"
	if err := os.MkdirAll(filepath.Dir(tmp), 0o755); err == nil {
		if err := os.WriteFile(tmp, data, 0o644); err == nil {
			_ = os.Rename(tmp, s.cfg.StateFile)
			return
		}
	}
	slog.Error("состояние не записано", "file", s.cfg.StateFile)
}

func (s *Store) Reset() {
	s.mu.Lock()
	defer s.mu.Unlock()
	offers, info := s.st.Offers, s.st.PriceList
	s.st = state{Seq: 550688000, Offers: offers, PriceList: info, Products: map[string]Entry{}}
	s.rnd = rand.New(rand.NewPCG(s.cfg.Seed, 7))
	s.reindex()
	s.seedLocked()
	s.save()
}

func (s *Store) SetPriceList(list *PriceList, source string) {
	s.mu.Lock()
	defer s.mu.Unlock()
	inStock := 0
	for _, o := range list.Offers {
		if _, count := o.AnyStore(); count > 0 {
			inStock++
		}
	}
	s.st.Offers = list.Offers
	s.st.PriceList = PriceListInfo{Source: source, LoadedAt: s.now(), Company: list.Company, Merchant: list.MerchantID, Offers: len(list.Offers), InStock: inStock}
	if !s.st.Seeded {
		s.seedLocked()
	}
	s.save()
}

func (s *Store) PriceListFailed(err error) {
	s.mu.Lock()
	defer s.mu.Unlock()
	s.st.PriceList.Error = err.Error()
	s.st.PriceList.FailedAt = s.now()
}

type Filter struct {
	States            []string
	Statuses          []string
	From, To          int64
	DeliveryType      string
	SignatureRequired *bool
	Code              string
	Page, Size        int
}

func (s *Store) List(f Filter) ([]Order, int) {
	s.mu.Lock()
	defer s.mu.Unlock()

	var matched []*Order
	for _, o := range s.st.Orders {
		switch {
		case f.Code != "" && o.Code != f.Code:
		case len(f.States) > 0 && !slices.Contains(f.States, o.State):
		case len(f.Statuses) > 0 && !slices.Contains(f.Statuses, o.Status):
		case f.From > 0 && o.CreationDate < f.From:
		case f.To > 0 && o.CreationDate > f.To:
		case f.DeliveryType != "" && o.DeliveryType() != f.DeliveryType:
		case f.SignatureRequired != nil && o.SignatureRequired != *f.SignatureRequired:
		default:
			matched = append(matched, o)
		}
	}
	slices.SortFunc(matched, func(a, b *Order) int { return int(b.CreationDate - a.CreationDate) })

	total := len(matched)
	start := min(f.Page*f.Size, total)
	end := min(start+f.Size, total)
	page := make([]Order, 0, end-start)
	for _, o := range matched[start:end] {
		page = append(page, *o)
	}
	return page, total
}

func (s *Store) Order(id string) (Order, bool) {
	s.mu.Lock()
	defer s.mu.Unlock()
	if o, ok := s.byID[id]; ok {
		return *o, true
	}
	return Order{}, false
}

func (s *Store) OrderByCode(code string) (Order, bool) {
	s.mu.Lock()
	defer s.mu.Unlock()
	for _, o := range s.st.Orders {
		if o.Code == code {
			return *o, true
		}
	}
	return Order{}, false
}

func (s *Store) Entry(id string) (Order, Entry, bool) {
	s.mu.Lock()
	defer s.mu.Unlock()
	for _, o := range s.st.Orders {
		for _, e := range o.Entries {
			if e.ID == id {
				return *o, e, true
			}
		}
	}
	return Order{}, Entry{}, false
}

func (s *Store) Product(masterID string) (Entry, bool) {
	s.mu.Lock()
	defer s.mu.Unlock()
	for _, p := range s.st.Products {
		if p.MasterID == masterID || p.MerchantID == masterID {
			return p, true
		}
	}
	return Entry{}, false
}

type Change struct {
	ID                  string
	Code                string
	Status              string
	CancellationReason  string
	CancellationComment string
	NumberOfSpace       int
	SendCode            bool
	SecurityCode        string
	HasSecurityHeader   bool
}

type ChangeResult struct {
	Order  Order
	Status string
}

func (s *Store) Change(c Change) (ChangeResult, error) {
	s.mu.Lock()
	defer s.mu.Unlock()

	o, ok := s.byID[c.ID]
	if !ok {
		return ChangeResult{}, &APIError{Status: 404, Title: "Заказ не найден"}
	}
	if c.Code != "" && c.Code != o.Code {
		return ChangeResult{}, badRequest("Код заказа %s не совпадает с id", c.Code)
	}

	now := s.now()
	result := c.Status

	switch c.Status {
	case StatusAccepted:
		if o.Status != StatusApproved || o.State != StateNew {
			return ChangeResult{}, badRequest("Принять можно только новый заказ в статусе APPROVED_BY_BANK, сейчас %s/%s", o.State, o.Status)
		}
		o.Status = StatusAccepted
		o.State = o.acceptedState()
		if o.IsKaspiDelivery {
			o.CourierTransmissionPlanningDate = s.nextWorkMorning(now).UnixMilli()
		}
		s.event(o, now, "Продавец принял заказ")

	case StatusAssemble:
		if o.Status != StatusAccepted || !o.IsKaspiDelivery {
			return ChangeResult{}, badRequest("Накладная формируется только для принятых заказов Kaspi Доставки")
		}
		if o.WaybillNumber != "" {
			return ChangeResult{}, badRequest("Накладная уже сформирована: %s", o.WaybillNumber)
		}
		if c.NumberOfSpace < 1 || c.NumberOfSpace > 50 {
			return ChangeResult{}, badRequest("numberOfSpace должно быть от 1 до 50")
		}
		o.NumberOfSpace = c.NumberOfSpace
		o.WaybillNumber = fmt.Sprintf("KD%s", o.Code)
		s.event(o, now, fmt.Sprintf("Сформирована накладная %s, мест: %d", o.WaybillNumber, o.NumberOfSpace))

	case StatusCompleted:
		if (o.Status != StatusAccepted && o.Status != StatusArrived) || o.IsKaspiDelivery {
			return ChangeResult{}, badRequest("Выдать можно принятый заказ самовывоза или доставки продавца, сейчас %s/%s", o.State, o.Status)
		}
		if !c.SendCode {
			return ChangeResult{}, badRequest("Нужен заголовок X-Send-Code: true")
		}
		if c.SecurityCode == "" {
			o.SecurityCode = fmt.Sprintf("%04d", s.rnd.IntN(10000))
			s.event(o, now, "Покупателю отправлен код в приложении Kaspi.kz")
			result = o.Status
			break
		}
		if o.SecurityCode == "" {
			return ChangeResult{}, badRequest("Сначала отправьте код покупателю запросом с пустым X-Security-Code")
		}
		if c.SecurityCode != o.SecurityCode {
			s.event(o, now, "Введён неверный код")
			s.touch(o, now)
			return ChangeResult{}, badRequest("Неверный код подтверждения")
		}
		o.Status, o.State, o.SecurityCode = StatusCompleted, StateArchive, ""
		s.event(o, now, "Заказ выдан покупателю")

	case StatusCancelled:
		if o.Status != StatusApproved && o.Status != StatusAccepted {
			return ChangeResult{}, badRequest("Отменить можно заказ в статусе APPROVED_BY_BANK или ACCEPTED_BY_MERCHANT, сейчас %s", o.Status)
		}
		if o.CourierTransmissionDate > 0 {
			return ChangeResult{}, badRequest("Заказ уже передан курьеру Kaspi Доставки")
		}
		if !slices.Contains(CancelReasons, c.CancellationReason) {
			return ChangeResult{}, badRequest("cancellationReason: одно из %v", CancelReasons)
		}
		if len([]rune(c.CancellationComment)) > 1000 {
			return ChangeResult{}, badRequest("cancellationComment длиннее 1000 знаков")
		}
		o.Status, o.State = StatusCancelled, StateArchive
		o.CancellationReason, o.CancellationComment = c.CancellationReason, c.CancellationComment
		s.event(o, now, "Продавец отменил заказ: "+c.CancellationReason)

	case StatusArrived:
		if o.Status != StatusAccepted || !o.PreOrder {
			return ChangeResult{}, badRequest("ARRIVED только для принятых заказов по предзаказу")
		}
		o.Status = StatusArrived
		s.event(o, now, "Товар по предзаказу прибыл на склад")

	default:
		return ChangeResult{}, badRequest("Недопустимый статус %q", c.Status)
	}

	s.touch(o, now)
	s.save()
	return ChangeResult{Order: *o, Status: result}, nil
}

type PartialCancel struct {
	EntryID          string  `json:"-"`
	RemainedQuantity int     `json:"remainedQuantity"`
	RemainedWeight   float64 `json:"remainedWeight"`
	Reason           string  `json:"reason"`
	Notes            string  `json:"notes"`
}

func (s *Store) PartialCancel(orderID string, items []PartialCancel) (Order, error) {
	s.mu.Lock()
	defer s.mu.Unlock()
	o, ok := s.byID[orderID]
	if !ok {
		return Order{}, &APIError{Status: 404, Title: "Заказ не найден"}
	}
	if o.Status != StatusAccepted {
		return Order{}, badRequest("Изменить состав можно только у принятого заказа")
	}
	for _, item := range items {
		idx := slices.IndexFunc(o.Entries, func(e Entry) bool { return e.ID == item.EntryID })
		if idx < 0 {
			return Order{}, badRequest("Позиция %s не найдена", item.EntryID)
		}
		if item.RemainedQuantity < 0 || item.RemainedQuantity > o.Entries[idx].Quantity {
			return Order{}, badRequest("remainedQuantity вне диапазона")
		}
	}
	now := s.now()
	for _, item := range items {
		idx := slices.IndexFunc(o.Entries, func(e Entry) bool { return e.ID == item.EntryID })
		e := &o.Entries[idx]
		e.Quantity = item.RemainedQuantity
		e.TotalPrice = e.BasePrice * float64(e.Quantity)
		s.event(o, now, fmt.Sprintf("Позиция %s: осталось %d шт. (%s)", e.Name, e.Quantity, item.Reason))
	}
	o.Entries = slices.DeleteFunc(o.Entries, func(e Entry) bool { return e.Quantity == 0 })
	o.TotalPrice = 0
	for _, e := range o.Entries {
		o.TotalPrice += e.TotalPrice
	}
	s.touch(o, now)
	s.save()
	return *o, nil
}

func (s *Store) BuyerCancel(code string) error {
	return s.mutate(code, func(o *Order, now time.Time) error {
		if o.Final() || o.CourierTransmissionDate > 0 {
			return errors.New("заказ уже нельзя отменить")
		}
		o.Status, o.State = StatusCancelled, StateArchive
		o.CancellationReason = "BUYER_CANCELLATION"
		s.event(o, now, "Покупатель отменил заказ в приложении")
		return nil
	})
}

func (s *Store) RequestReturn(code string) error {
	return s.mutate(code, func(o *Order, now time.Time) error {
		if o.Status != StatusCompleted || !o.IsKaspiDelivery {
			return errors.New("возврат оформляется только по выполненным заказам Kaspi Доставки")
		}
		o.Status = StatusReturnRequested
		s.event(o, now, "Покупатель оформил возврат")
		return nil
	})
}

func (s *Store) CompleteReturn(code string) error {
	return s.mutate(code, func(o *Order, now time.Time) error {
		if o.Status != StatusReturnRequested {
			return errors.New("возврат не запрошен")
		}
		o.Status, o.ReturnedToWarehouse = StatusReturned, true
		s.event(o, now, "Товар вернулся на склад продавца")
		return nil
	})
}

func (s *Store) mutate(code string, fn func(*Order, time.Time) error) error {
	s.mu.Lock()
	defer s.mu.Unlock()
	for _, o := range s.st.Orders {
		if o.Code == code {
			now := s.now()
			if err := fn(o, now); err != nil {
				return err
			}
			s.touch(o, now)
			s.save()
			return nil
		}
	}
	return errors.New("заказ не найден")
}

func (s *Store) Tick() {
	s.mu.Lock()
	defer s.mu.Unlock()
	now := s.now()
	changed := false
	for _, o := range s.st.Orders {
		age := now.Sub(time.UnixMilli(o.UpdatedAt))
		switch {
		case o.State == StateSignRequired && age >= s.cfg.SignAfter:
			o.State = StateNew
			s.event(o, now, "Покупатель подписал документы по кредиту")
		case o.State == StateNew && s.cfg.AcceptTimeout > 0 && now.Sub(time.UnixMilli(o.CreationDate)) >= s.cfg.AcceptTimeout:
			o.Status, o.State, o.CancellationReason = StatusCancelled, StateArchive, "MERCHANT_ACCEPT_TIMEOUT"
			s.event(o, now, "Отменён: продавец не принял заказ вовремя")
		case o.Status == StatusAccepted && o.WaybillNumber != "" && o.CourierTransmissionDate == 0 && age >= s.cfg.CourierAfter:
			o.CourierTransmissionDate = now.UnixMilli()
			s.event(o, now, "Курьер Kaspi Доставки забрал заказ")
		case o.Status == StatusAccepted && o.CourierTransmissionDate > 0 && age >= s.cfg.CourierAfter:
			o.Status, o.State = StatusCompleted, StateArchive
			s.event(o, now, "Kaspi Доставка вручила заказ покупателю")
		case o.Status == StatusReturnRequested && age >= 2*s.cfg.CourierAfter:
			o.Status, o.ReturnedToWarehouse = StatusReturned, true
			s.event(o, now, "Товар вернулся на склад продавца")
		default:
			continue
		}
		s.touch(o, now)
		changed = true
	}
	if changed {
		s.save()
	}
}

func (s *Store) NewOrder() (Order, error) {
	s.mu.Lock()
	defer s.mu.Unlock()
	o := s.generate(s.now(), false)
	if o == nil {
		return Order{}, errors.New("в прайс-листе нет товаров в наличии")
	}
	s.save()
	return *o, nil
}

type Snapshot struct {
	PriceList PriceListInfo `json:"priceList"`
	Offers    []Offer       `json:"offers"`
	Orders    []Order       `json:"orders"`
	Total     int           `json:"total"`
}

func (s *Store) Snapshot(limit int) Snapshot {
	s.mu.Lock()
	defer s.mu.Unlock()
	orders := slices.Clone(s.st.Orders)
	slices.SortFunc(orders, func(a, b *Order) int { return int(b.UpdatedAt - a.UpdatedAt) })
	out := make([]Order, 0, min(limit, len(orders)))
	for _, o := range orders[:min(limit, len(orders))] {
		out = append(out, *o)
	}
	return Snapshot{PriceList: s.st.PriceList, Offers: s.st.Offers, Orders: out, Total: len(s.st.Orders)}
}

func (s *Store) event(o *Order, at time.Time, text string) {
	o.Events = append(o.Events, Event{At: at.UnixMilli(), Text: text})
}

func (s *Store) touch(o *Order, at time.Time) {
	o.UpdatedAt = at.UnixMilli()
}

func (s *Store) nextWorkMorning(now time.Time) time.Time {
	local := now.In(s.cfg.Location)
	next := time.Date(local.Year(), local.Month(), local.Day()+1, 10, 0, 0, 0, s.cfg.Location)
	for next.Weekday() == time.Sunday {
		next = next.AddDate(0, 0, 1)
	}
	return next
}

func (s *Store) nextID() int64 {
	s.st.Seq += int64(1 + s.rnd.IntN(40))
	return s.st.Seq
}

func (s *Store) code() string {
	for {
		code := strconv.Itoa(100000000 + s.rnd.IntN(800000000))
		if !slices.ContainsFunc(s.st.Orders, func(o *Order) bool { return o.Code == code }) {
			return code
		}
	}
}
