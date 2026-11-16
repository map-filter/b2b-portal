package kaspi

import (
	"crypto/subtle"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"slices"
	"strconv"
	"strings"
	"time"
)

const jsonAPI = "application/vnd.api+json"

type API struct {
	store *Store
	cfg   Config
}

func NewAPI(store *Store, cfg Config) *API {
	return &API{store: store, cfg: cfg}
}

func (a *API) Register(mux *http.ServeMux) {
	v2 := func(pattern string, h http.HandlerFunc) {
		method, path, _ := strings.Cut(pattern, " ")
		mux.HandleFunc(method+" /shop/api/v2"+path, a.auth(h))
	}
	v2("GET /orders", a.listOrders)
	v2("POST /orders", a.changeOrder)
	v2("GET /orders/{id}", a.showOrder)
	v2("GET /orders/{id}/entries", a.orderEntries)
	v2("GET /orders/{id}/user", a.orderUser)
	v2("GET /orderentries/{id}", a.showEntry)
	v2("GET /orderentries/{id}/product", a.entryProduct)
	v2("GET /orderentries/{id}/deliveryPointOfService", a.entryPointOfService)
	v2("GET /pointofservices/{id}", a.pointOfService)
	v2("GET /masterproducts/{id}", a.masterProduct)
	v2("GET /masterproducts/{id}/merchantProduct", a.merchantProduct)
	v2("GET /merchantproducts/{id}", a.merchantProduct)
	mux.HandleFunc("PUT /shop/api/orderPartialCancel/{id}", a.auth(a.partialCancel))
	mux.HandleFunc("GET /shop/api/waybill/{code}", a.waybill)
}

func (a *API) auth(next http.HandlerFunc) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		token := r.Header.Get("X-Auth-Token")
		if token == "" || subtle.ConstantTimeCompare([]byte(token), []byte(a.cfg.Token)) != 1 {
			writeError(w, &APIError{Status: http.StatusUnauthorized, Title: "Неверный или отсутствующий X-Auth-Token"})
			return
		}
		if r.Method != http.MethodGet && !strings.HasPrefix(r.Header.Get("Content-Type"), jsonAPI) {
			writeError(w, &APIError{Status: http.StatusUnsupportedMediaType, Title: "Content-Type должен быть " + jsonAPI})
			return
		}
		next(w, r)
	}
}

func (a *API) listOrders(w http.ResponseWriter, r *http.Request) {
	q := r.URL.Query()
	f := Filter{Code: q.Get("filter[orders][code]")}
	var err error

	if f.Page, err = intParam(q.Get("page[number]"), 0); err != nil || f.Page < 0 {
		writeError(w, badRequest("page[number] должен быть числом от 0"))
		return
	}
	if f.Size, err = intParam(q.Get("page[size]"), 20); err != nil || f.Size < 1 || f.Size > 100 {
		writeError(w, badRequest("page[size] от 1 до 100"))
		return
	}
	for _, state := range splitList(q.Get("filter[orders][state]")) {
		if !slices.Contains(States, state) {
			writeError(w, badRequest("Неизвестное состояние %q", state))
			return
		}
		f.States = append(f.States, state)
	}
	f.Statuses = splitList(q.Get("filter[orders][status]"))
	if f.From, err = int64Param(q.Get("filter[orders][creationDate][$ge]")); err != nil {
		writeError(w, badRequest("creationDate[$ge] в миллисекундах"))
		return
	}
	if f.To, err = int64Param(q.Get("filter[orders][creationDate][$le]")); err != nil {
		writeError(w, badRequest("creationDate[$le] в миллисекундах"))
		return
	}
	if f.Code == "" {
		if f.From == 0 {
			writeError(w, badRequest("Укажите filter[orders][creationDate][$ge]"))
			return
		}
		to := f.To
		if to == 0 {
			to = a.store.now().UnixMilli()
		}
		if a.cfg.MaxRangeDays > 0 && time.Duration(to-f.From)*time.Millisecond > time.Duration(a.cfg.MaxRangeDays)*24*time.Hour {
			writeError(w, badRequest("Период creationDate не больше %d дней", a.cfg.MaxRangeDays))
			return
		}
	}
	if dt := q.Get("filter[orders][deliveryType]"); dt != "" {
		if !slices.Contains([]string{"PICKUP", "DELIVERY"}, dt) {
			writeError(w, badRequest("deliveryType: PICKUP или DELIVERY"))
			return
		}
		f.DeliveryType = dt
	}
	if sr := q.Get("filter[orders][signatureRequired]"); sr != "" {
		value, err := strconv.ParseBool(sr)
		if err != nil {
			writeError(w, badRequest("signatureRequired: true или false"))
			return
		}
		f.SignatureRequired = &value
	}

	orders, total := a.store.List(f)
	withUser := q.Get("include[orders]") == "user"
	data := make([]resource, 0, len(orders))
	included := []resource{}
	seen := map[string]bool{}
	for _, o := range orders {
		data = append(data, a.orderResource(o, true))
		if withUser && !seen[o.CustomerID] {
			seen[o.CustomerID] = true
			included = append(included, customerResource(o))
		}
	}
	pages := (total + f.Size - 1) / f.Size
	writeJSON(w, http.StatusOK, map[string]any{
		"data":     data,
		"included": included,
		"meta":     map[string]int{"pageCount": pages, "totalCount": total},
	})
}

func (a *API) showOrder(w http.ResponseWriter, r *http.Request) {
	o, ok := a.store.Order(r.PathValue("id"))
	if !ok {
		writeError(w, &APIError{Status: http.StatusNotFound, Title: "Заказ не найден"})
		return
	}
	writeDoc(w, a.orderResource(o, true))
}

func (a *API) orderUser(w http.ResponseWriter, r *http.Request) {
	o, ok := a.store.Order(r.PathValue("id"))
	if !ok {
		writeError(w, &APIError{Status: http.StatusNotFound, Title: "Заказ не найден"})
		return
	}
	writeDoc(w, customerResource(o))
}

func (a *API) orderEntries(w http.ResponseWriter, r *http.Request) {
	o, ok := a.store.Order(r.PathValue("id"))
	if !ok {
		writeError(w, &APIError{Status: http.StatusNotFound, Title: "Заказ не найден"})
		return
	}
	data := make([]resource, 0, len(o.Entries))
	for _, e := range o.Entries {
		data = append(data, a.entryResource(e))
	}
	writeJSON(w, http.StatusOK, map[string]any{"data": data, "included": []resource{}})
}

func (a *API) showEntry(w http.ResponseWriter, r *http.Request) {
	_, e, ok := a.store.Entry(r.PathValue("id"))
	if !ok {
		writeError(w, &APIError{Status: http.StatusNotFound, Title: "Позиция не найдена"})
		return
	}
	writeDoc(w, a.entryResource(e))
}

func (a *API) entryProduct(w http.ResponseWriter, r *http.Request) {
	_, e, ok := a.store.Entry(r.PathValue("id"))
	if !ok {
		writeError(w, &APIError{Status: http.StatusNotFound, Title: "Позиция не найдена"})
		return
	}
	writeDoc(w, a.masterResource(e))
}

func (a *API) entryPointOfService(w http.ResponseWriter, r *http.Request) {
	_, e, ok := a.store.Entry(r.PathValue("id"))
	if !ok {
		writeError(w, &APIError{Status: http.StatusNotFound, Title: "Позиция не найдена"})
		return
	}
	writeDoc(w, a.posResource(e.StoreID))
}

func (a *API) pointOfService(w http.ResponseWriter, r *http.Request) {
	writeDoc(w, a.posResource(r.PathValue("id")))
}

func (a *API) masterProduct(w http.ResponseWriter, r *http.Request) {
	p, ok := a.store.Product(r.PathValue("id"))
	if !ok {
		writeError(w, &APIError{Status: http.StatusNotFound, Title: "Товар не найден"})
		return
	}
	writeDoc(w, a.masterResource(p))
}

func (a *API) merchantProduct(w http.ResponseWriter, r *http.Request) {
	p, ok := a.store.Product(r.PathValue("id"))
	if !ok {
		writeError(w, &APIError{Status: http.StatusNotFound, Title: "Товар не найден"})
		return
	}
	writeDoc(w, resource{
		Type:          "merchantproducts",
		ID:            p.MerchantID,
		Attributes:    map[string]any{"code": p.SKU, "name": p.Name},
		Relationships: map[string]any{},
		Links:         map[string]string{"self": a.url("/merchantproducts/" + p.MerchantID)},
	})
}

type changeRequest struct {
	Data struct {
		Type       string `json:"type"`
		ID         string `json:"id"`
		Attributes struct {
			Code                string          `json:"code"`
			Status              string          `json:"status"`
			CancellationReason  string          `json:"cancellationReason"`
			CancellationComment string          `json:"cancellationComment"`
			NumberOfSpace       json.RawMessage `json:"numberOfSpace"`
		} `json:"attributes"`
	} `json:"data"`
}

func (a *API) changeOrder(w http.ResponseWriter, r *http.Request) {
	var req changeRequest
	if err := json.NewDecoder(io.LimitReader(r.Body, 1<<20)).Decode(&req); err != nil {
		writeError(w, badRequest("Тело запроса не JSON: %v", err))
		return
	}
	if req.Data.Type != "orders" {
		writeError(w, badRequest("data.type должен быть orders"))
		return
	}
	attrs := req.Data.Attributes
	spaces, err := flexInt(attrs.NumberOfSpace)
	if err != nil {
		writeError(w, badRequest("numberOfSpace должно быть числом"))
		return
	}
	code, hasCode := r.Header["X-Security-Code"]
	result, err := a.store.Change(Change{
		ID:                  strings.TrimSpace(req.Data.ID),
		Code:                attrs.Code,
		Status:              attrs.Status,
		CancellationReason:  attrs.CancellationReason,
		CancellationComment: attrs.CancellationComment,
		NumberOfSpace:       spaces,
		SendCode:            r.Header.Get("X-Send-Code") == "true",
		SecurityCode:        strings.TrimSpace(strings.Join(code, "")),
		HasSecurityHeader:   hasCode,
	})
	if err != nil {
		writeError(w, err)
		return
	}

	o := result.Order
	out := map[string]any{"code": o.Code, "status": result.Status}
	switch attrs.Status {
	case StatusAssemble:
		out = map[string]any{"numberOfSpace": o.NumberOfSpace, "status": StatusAssemble}
	case StatusCancelled:
		out = map[string]any{"cancellationReason": o.CancellationReason, "status": o.Status}
	}
	res := a.orderResource(o, false)
	res.Attributes = out
	writeDoc(w, res)
}

func (a *API) partialCancel(w http.ResponseWriter, r *http.Request) {
	var items []struct {
		Entry struct {
			ID string `json:"id"`
		} `json:"entry"`
		PartialCancel
	}
	if err := json.NewDecoder(io.LimitReader(r.Body, 1<<20)).Decode(&items); err != nil || len(items) == 0 {
		writeError(w, badRequest("Ожидается непустой массив позиций"))
		return
	}
	req := make([]PartialCancel, 0, len(items))
	for _, item := range items {
		p := item.PartialCancel
		p.EntryID = item.Entry.ID
		req = append(req, p)
	}
	if _, err := a.store.PartialCancel(r.PathValue("id"), req); err != nil {
		writeError(w, err)
		return
	}
	entries := make([]map[string]any, 0, len(req))
	for i, item := range req {
		entries = append(entries, map[string]any{
			"id":               encodeID(int64(fnv32(item.EntryID)) + int64(i)),
			"notes":            item.Notes,
			"entry":            map[string]string{"id": item.EntryID},
			"remainedQuantity": item.RemainedQuantity,
			"remainedWeight":   item.RemainedWeight,
		})
	}
	writeJSON(w, http.StatusOK, map[string]any{"orderCancelEntries": entries, "status": "INPROGRESS"})
}

func (a *API) waybill(w http.ResponseWriter, r *http.Request) {
	o, ok := a.store.OrderByCode(strings.TrimSuffix(r.PathValue("code"), ".pdf"))
	if !ok || o.WaybillNumber == "" {
		http.NotFound(w, r)
		return
	}
	w.Header().Set("Content-Type", "application/pdf")
	w.Header().Set("Content-Disposition", fmt.Sprintf(`inline; filename="waybill-%s.pdf"`, o.Code))
	_, _ = w.Write(WaybillPDF(o))
}

type resource struct {
	Type          string            `json:"type"`
	ID            string            `json:"id"`
	Attributes    map[string]any    `json:"attributes"`
	Relationships map[string]any    `json:"relationships"`
	Links         map[string]string `json:"links"`
}

func (a *API) url(path string) string {
	return strings.TrimRight(a.cfg.PublicURL, "/") + "/shop/api/v2" + path
}

func (a *API) rel(base, name string, data any) map[string]any {
	out := map[string]any{"links": map[string]string{
		"self":    a.url(base + "/relationships/" + name),
		"related": a.url(base + "/" + name),
	}}
	if data != nil {
		out["data"] = data
	}
	return out
}

func (a *API) orderResource(o Order, full bool) resource {
	base := "/orders/" + o.ID
	res := resource{
		Type: "orders",
		ID:   o.ID,
		Relationships: map[string]any{
			"user":    a.rel(base, "user", map[string]string{"type": "customers", "id": o.CustomerID}),
			"entries": a.rel(base, "entries", nil),
		},
		Links: map[string]string{"self": a.url(base)},
	}
	if !full {
		res.Relationships["user"] = map[string]any{"links": res.Relationships["user"].(map[string]any)["links"], "data": nil}
		return res
	}
	attrs := map[string]any{
		"code":                  o.Code,
		"totalPrice":            o.TotalPrice,
		"paymentMode":           o.PaymentMode,
		"plannedDeliveryDate":   o.PlannedDeliveryDate,
		"creationDate":          o.CreationDate,
		"approvedByBankDate":    o.ApprovedByBankDate,
		"deliveryCostForSeller": o.DeliveryCostForSeller,
		"isKaspiDelivery":       o.IsKaspiDelivery,
		"deliveryMode":          o.DeliveryMode,
		"signatureRequired":     o.SignatureRequired,
		"preOrder":              o.PreOrder,
		"express":               o.Express,
		"returnedToWarehouse":   o.ReturnedToWarehouse,
		"isImeiRequired":        false,
		"state":                 o.State,
		"status":                o.Status,
		"deliveryCost":          o.DeliveryCost,
		"customer":              map[string]string{"name": o.Customer.FirstName + " " + o.Customer.LastName, "firstName": o.Customer.FirstName, "lastName": o.Customer.LastName, "cellPhone": o.Customer.CellPhone},
		"recipient":             o.Customer,
		"deliveryAddress":       o.DeliveryAddress,
		"pickupPointId":         o.StoreID,
	}
	if o.CreditTerm > 0 {
		attrs["creditTerm"] = o.CreditTerm
	}
	if o.CancellationReason != "" {
		attrs["cancellationReason"] = o.CancellationReason
		attrs["cancellationComment"] = o.CancellationComment
	}
	if o.IsKaspiDelivery {
		attrs["courierTransmissionPlanningDate"] = nullable(o.CourierTransmissionPlanningDate)
		attrs["courierTransmissionDate"] = nullable(o.CourierTransmissionDate)
		if o.WaybillNumber != "" {
			attrs["waybill"] = strings.TrimRight(a.cfg.PublicURL, "/") + "/shop/api/waybill/" + o.Code + ".pdf"
			attrs["waybillNumber"] = o.WaybillNumber
			attrs["numberOfSpace"] = o.NumberOfSpace
		}
	}
	res.Attributes = attrs
	return res
}

func customerResource(o Order) resource {
	return resource{
		Type:          "customers",
		ID:            o.CustomerID,
		Attributes:    map[string]any{"firstName": o.Customer.FirstName, "lastName": o.Customer.LastName, "cellPhone": o.Customer.CellPhone},
		Relationships: map[string]any{},
		Links:         map[string]string{},
	}
}

func (a *API) entryResource(e Entry) resource {
	base := "/orderentries/" + e.ID
	return resource{
		Type: "orderentries",
		ID:   e.ID,
		Attributes: map[string]any{
			"unitType":     "PIECE",
			"quantity":     e.Quantity,
			"totalPrice":   e.TotalPrice,
			"weight":       e.Weight,
			"entryNumber":  e.EntryNumber,
			"category":     map[string]string{"code": "Master - " + e.Category, "title": e.Category},
			"deliveryCost": e.DeliveryCost,
			"basePrice":    e.BasePrice,
			"offer":        map[string]string{"code": e.SKU, "name": e.Name},
		},
		Relationships: map[string]any{
			"deliveryPointOfService": a.rel(base, "deliveryPointOfService", map[string]string{"type": "pointofservices", "id": e.StoreID}),
			"product":                a.rel(base, "product", map[string]string{"type": "masterproducts", "id": e.MasterID}),
		},
		Links: map[string]string{"self": a.url(base)},
	}
}

func (a *API) masterResource(e Entry) resource {
	base := "/masterproducts/" + e.MasterID
	return resource{
		Type:          "masterproducts",
		ID:            e.MasterID,
		Attributes:    map[string]any{"code": strconv.FormatUint(uint64(fnv32(e.SKU)), 10), "name": e.Name, "manufacturer": e.Brand, "category": e.Category},
		Relationships: map[string]any{"merchantProduct": a.rel(base, "merchantProduct", map[string]string{"type": "merchantproducts", "id": e.MerchantID})},
		Links:         map[string]string{"self": a.url(base)},
	}
}

func (a *API) posResource(id string) resource {
	base := "/pointofservices/" + id
	return resource{
		Type:          "pointofservices",
		ID:            id,
		Attributes:    map[string]any{"address": StoreAddress(id), "displayName": id},
		Relationships: map[string]any{"city": a.rel(base, "city", map[string]string{"type": "cities", "id": "551010000"})},
		Links:         map[string]string{"self": a.url(base)},
	}
}

func nullable(ms int64) any {
	if ms == 0 {
		return nil
	}
	return ms
}

func writeDoc(w http.ResponseWriter, res resource) {
	writeJSON(w, http.StatusOK, map[string]any{"data": res, "included": []resource{}})
}

func writeJSON(w http.ResponseWriter, status int, body any) {
	w.Header().Set("Content-Type", jsonAPI)
	w.WriteHeader(status)
	_ = json.NewEncoder(w).Encode(body)
}

func writeError(w http.ResponseWriter, err error) {
	var apiErr *APIError
	if !errors.As(err, &apiErr) {
		apiErr = &APIError{Status: http.StatusInternalServerError, Title: err.Error()}
	}
	writeJSON(w, apiErr.Status, map[string]any{"errors": []map[string]string{{
		"status": strconv.Itoa(apiErr.Status),
		"title":  apiErr.Title,
	}}})
}

func intParam(raw string, fallback int) (int, error) {
	if raw == "" {
		return fallback, nil
	}
	return strconv.Atoi(raw)
}

func int64Param(raw string) (int64, error) {
	if raw == "" {
		return 0, nil
	}
	return strconv.ParseInt(raw, 10, 64)
}

func flexInt(raw json.RawMessage) (int, error) {
	if len(raw) == 0 || string(raw) == "null" {
		return 0, nil
	}
	var n int
	if err := json.Unmarshal(raw, &n); err == nil {
		return n, nil
	}
	var s string
	if err := json.Unmarshal(raw, &s); err != nil {
		return 0, err
	}
	return strconv.Atoi(strings.TrimSpace(s))
}

func splitList(raw string) []string {
	if raw == "" {
		return nil
	}
	var out []string
	for _, part := range strings.Split(raw, ",") {
		if part = strings.TrimSpace(part); part != "" {
			out = append(out, part)
		}
	}
	return out
}
