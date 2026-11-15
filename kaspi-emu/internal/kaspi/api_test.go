package kaspi

import (
	"encoding/json"
	"fmt"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"
)

const samplePriceList = `<?xml version="1.0" encoding="utf-8"?>
<kaspi_catalog date="string" xmlns="kaspiShopping" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">
  <company>ЭлектроОпт (демо)</company>
  <merchantid>DEMO01</merchantid>
  <offers>
    <offer sku="DEMO-1001"><model>Кабель ВВГнг 3х2,5</model><brand>Кабэкс</brand>
      <availabilities><availability available="yes" storeId="PP1" stockCount="40"/></availabilities><price>890</price></offer>
    <offer sku="DEMO-1002"><model>Автомат 1P 16А</model><brand>IEK</brand>
      <availabilities><availability available="yes" storeId="PP1" stockCount="12"/></availabilities>
      <cityprices><cityprice cityId="551010000">2450</cityprice></cityprices></offer>
    <offer sku="DEMO-1003"><model>Розетка двойная</model><brand>Schneider</brand>
      <availabilities><availability available="no" storeId="PP1"/></availabilities><price>1500</price></offer>
  </offers>
</kaspi_catalog>`

type fixture struct {
	t      *testing.T
	store  *Store
	server *httptest.Server
	now    time.Time
}

func newFixture(t *testing.T, history int) *fixture {
	t.Helper()
	f := &fixture{t: t, now: time.Date(2026, 11, 15, 12, 0, 0, 0, time.UTC)}
	cfg := Config{Token: "secret", PublicURL: "http://emu", MaxRangeDays: 14, SignAfter: time.Minute, CourierAfter: time.Minute, HistoryDays: history, OrdersPerDay: 4}
	f.store = NewStore(cfg)
	f.store.SetClock(func() time.Time { return f.now })
	list, err := ParsePriceList(strings.NewReader(samplePriceList))
	if err != nil {
		t.Fatal(err)
	}
	f.store.SetPriceList(list, "test")
	mux := http.NewServeMux()
	NewAPI(f.store, cfg).Register(mux)
	f.server = httptest.NewServer(mux)
	t.Cleanup(f.server.Close)
	return f
}

func (f *fixture) do(method, path, body string, headers ...string) (int, map[string]any) {
	f.t.Helper()
	req, _ := http.NewRequest(method, f.server.URL+path, strings.NewReader(body))
	req.Header.Set("X-Auth-Token", "secret")
	req.Header.Set("Content-Type", jsonAPI)
	for i := 0; i+1 < len(headers); i += 2 {
		req.Header[headers[i]] = []string{headers[i+1]}
	}
	resp, err := http.DefaultClient.Do(req)
	if err != nil {
		f.t.Fatal(err)
	}
	defer resp.Body.Close()
	var out map[string]any
	_ = json.NewDecoder(resp.Body).Decode(&out)
	return resp.StatusCode, out
}

func (f *fixture) change(id, attrs string, headers ...string) (int, map[string]any) {
	return f.do("POST", "/shop/api/v2/orders", fmt.Sprintf(`{"data":{"type":"orders","id":%q,"attributes":%s}}`, id, attrs), headers...)
}

func (f *fixture) since(days int) string {
	return fmt.Sprint(f.now.AddDate(0, 0, -days).UnixMilli())
}

func (f *fixture) newOrder(mode string, kaspiDelivery bool) Order {
	f.t.Helper()
	o, err := f.store.NewOrder()
	if err != nil {
		f.t.Fatal(err)
	}
	f.store.mu.Lock()
	stored := f.store.byID[o.ID]
	stored.DeliveryMode, stored.IsKaspiDelivery, stored.State = mode, kaspiDelivery, StateNew
	f.store.mu.Unlock()
	return *stored
}

func TestAuthAndContentType(t *testing.T) {
	f := newFixture(t, 0)

	req, _ := http.NewRequest("GET", f.server.URL+"/shop/api/v2/orders", nil)
	resp, _ := http.DefaultClient.Do(req)
	if resp.StatusCode != 401 {
		t.Fatalf("без токена ждали 401, получили %d", resp.StatusCode)
	}

	req, _ = http.NewRequest("POST", f.server.URL+"/shop/api/v2/orders", strings.NewReader("{}"))
	req.Header.Set("X-Auth-Token", "secret")
	req.Header.Set("Content-Type", "application/json")
	resp, _ = http.DefaultClient.Do(req)
	if resp.StatusCode != 415 {
		t.Fatalf("без application/vnd.api+json ждали 415, получили %d", resp.StatusCode)
	}
}

func TestListFiltersAndPaging(t *testing.T) {
	f := newFixture(t, 10)

	status, body := f.do("GET", "/shop/api/v2/orders?page[number]=0&page[size]=5&filter[orders][creationDate][$ge]="+f.since(12)+"&include[orders]=user", "")
	if status != 200 {
		t.Fatalf("статус %d: %v", status, body)
	}
	meta := body["meta"].(map[string]any)
	total := int(meta["totalCount"].(float64))
	if total < 10 || len(body["data"].([]any)) != 5 || int(meta["pageCount"].(float64)) != (total+4)/5 {
		t.Fatalf("пагинация: %v", meta)
	}
	if len(body["included"].([]any)) == 0 {
		t.Fatal("include[orders]=user не вернул покупателей")
	}

	_, body = f.do("GET", "/shop/api/v2/orders?page[size]=100&filter[orders][state]=NEW&filter[orders][creationDate][$ge]="+f.since(12), "")
	for _, item := range body["data"].([]any) {
		if state := item.(map[string]any)["attributes"].(map[string]any)["state"]; state != StateNew {
			t.Fatalf("фильтр по состоянию пропустил %v", state)
		}
	}

	for _, query := range []string{
		"page[size]=101&filter[orders][creationDate][$ge]=" + f.since(1),
		"filter[orders][state]=WRONG&filter[orders][creationDate][$ge]=" + f.since(1),
		"filter[orders][creationDate][$ge]=" + f.since(20),
		"page[size]=10",
	} {
		if status, _ := f.do("GET", "/shop/api/v2/orders?"+query, ""); status != 400 {
			t.Fatalf("%s: ждали 400, получили %d", query, status)
		}
	}
}

func TestEntriesLeadToMerchantSku(t *testing.T) {
	f := newFixture(t, 0)
	o := f.newOrder(ModePickup, false)

	_, entries := f.do("GET", "/shop/api/v2/orders/"+o.ID+"/entries", "")
	entry := entries["data"].([]any)[0].(map[string]any)
	_, product := f.do("GET", "/shop/api/v2/orderentries/"+entry["id"].(string)+"/product", "")
	masterID := product["data"].(map[string]any)["id"].(string)
	_, merchant := f.do("GET", "/shop/api/v2/masterproducts/"+masterID+"/merchantProduct", "")
	sku := merchant["data"].(map[string]any)["attributes"].(map[string]any)["code"]
	if sku != o.Entries[0].SKU {
		t.Fatalf("ждали sku %s, получили %v", o.Entries[0].SKU, sku)
	}
	if strings.HasPrefix(o.Entries[0].SKU, "DEMO-1003") {
		t.Fatal("заказан товар, которого нет в наличии")
	}
}

func TestPickupFlowWithSecurityCode(t *testing.T) {
	f := newFixture(t, 0)
	o := f.newOrder(ModePickup, false)

	if status, body := f.change(o.ID, `{"code":"`+o.Code+`","status":"ACCEPTED_BY_MERCHANT"}`); status != 200 || attr(body, "status") != StatusAccepted {
		t.Fatalf("принятие: %d %v", status, body)
	}
	if got, _ := f.store.Order(o.ID); got.State != StatePickup {
		t.Fatalf("после принятия самовывоз должен быть PICKUP, а не %s", got.State)
	}
	if status, _ := f.change(o.ID, `{"code":"`+o.Code+`","status":"ACCEPTED_BY_MERCHANT"}`); status != 400 {
		t.Fatal("повторное принятие должно давать 400")
	}
	if status, _ := f.change(o.ID, `{"code":"`+o.Code+`","status":"COMPLETED"}`); status != 400 {
		t.Fatal("выдача без X-Send-Code должна давать 400")
	}

	f.change(o.ID, `{"code":"`+o.Code+`","status":"COMPLETED"}`, "X-Send-Code", "true", "X-Security-Code", "")
	sent, _ := f.store.Order(o.ID)
	if len(sent.SecurityCode) != 4 || sent.Status != StatusAccepted {
		t.Fatalf("код не отправлен: %+v", sent)
	}
	if status, _ := f.change(o.ID, `{"code":"`+o.Code+`","status":"COMPLETED"}`, "X-Send-Code", "true", "X-Security-Code", "0000x"); status != 400 {
		t.Fatal("неверный код должен давать 400")
	}
	status, body := f.change(o.ID, `{"code":"`+o.Code+`","status":"COMPLETED"}`, "X-Send-Code", "true", "X-Security-Code", sent.SecurityCode)
	if status != 200 || attr(body, "status") != StatusCompleted {
		t.Fatalf("выдача по коду: %d %v", status, body)
	}
	if done, _ := f.store.Order(o.ID); done.State != StateArchive {
		t.Fatalf("выданный заказ должен уйти в архив, а он %s", done.State)
	}
}

func TestKaspiDeliveryWaybillAndCourier(t *testing.T) {
	f := newFixture(t, 0)
	o := f.newOrder(ModeLocal, true)
	f.change(o.ID, `{"code":"`+o.Code+`","status":"ACCEPTED_BY_MERCHANT"}`)

	if status, _ := f.change(o.ID, `{"status":"ASSEMBLE","numberOfSpace":"0"}`); status != 400 {
		t.Fatal("0 мест должно давать 400")
	}
	status, body := f.change(o.ID, `{"status":"ASSEMBLE","numberOfSpace":"2"}`)
	if status != 200 || attr(body, "status") != StatusAssemble || attr(body, "numberOfSpace") != float64(2) {
		t.Fatalf("накладная: %d %v", status, body)
	}
	_, list := f.do("GET", "/shop/api/v2/orders?filter[orders][code]="+o.Code, "")
	attrs := list["data"].([]any)[0].(map[string]any)["attributes"].(map[string]any)
	if attrs["waybill"] != "http://emu/shop/api/waybill/"+o.Code+".pdf" || attrs["state"] != StateKaspiDelivery {
		t.Fatalf("в заказе нет накладной: %v", attrs)
	}
	if status, _ := f.change(o.ID, `{"code":"`+o.Code+`","status":"COMPLETED"}`, "X-Send-Code", "true", "X-Security-Code", ""); status != 400 {
		t.Fatal("заказ Kaspi Доставки продавец не выдаёт по коду")
	}

	f.now = f.now.Add(2 * time.Minute)
	f.store.Tick()
	f.now = f.now.Add(2 * time.Minute)
	f.store.Tick()
	if got, _ := f.store.Order(o.ID); got.Status != StatusCompleted || got.CourierTransmissionDate == 0 {
		t.Fatalf("курьер не довёз заказ: %s", got.Status)
	}
	if !strings.HasPrefix(string(WaybillPDF(*o.withWaybill())), "%PDF-1.4") {
		t.Fatal("накладная не PDF")
	}
}

func TestCancelRules(t *testing.T) {
	f := newFixture(t, 0)
	o := f.newOrder(ModePickup, false)

	if status, _ := f.change(o.ID, `{"code":"`+o.Code+`","status":"CANCELLED","cancellationReason":"NO_REASON"}`); status != 400 {
		t.Fatal("неизвестная причина должна давать 400")
	}
	status, body := f.change(o.ID, `{"code":"`+o.Code+`","status":"CANCELLED","cancellationReason":"MERCHANT_OUT_OF_STOCK"}`)
	if status != 200 || attr(body, "cancellationReason") != "MERCHANT_OUT_OF_STOCK" {
		t.Fatalf("отмена: %d %v", status, body)
	}
	if status, _ := f.change(o.ID, `{"code":"`+o.Code+`","status":"ACCEPTED_BY_MERCHANT"}`); status != 400 {
		t.Fatal("отменённый заказ нельзя принять")
	}
}

func TestSignRequiredBecomesNew(t *testing.T) {
	f := newFixture(t, 0)
	o := f.newOrder(ModePickup, false)
	f.store.mu.Lock()
	f.store.byID[o.ID].State = StateSignRequired
	f.store.mu.Unlock()

	if status, _ := f.change(o.ID, `{"code":"`+o.Code+`","status":"ACCEPTED_BY_MERCHANT"}`); status != 400 {
		t.Fatal("заказ без подписи нельзя принять")
	}
	f.now = f.now.Add(2 * time.Minute)
	f.store.Tick()
	if got, _ := f.store.Order(o.ID); got.State != StateNew {
		t.Fatalf("после подписи ждали NEW, а не %s", got.State)
	}
}

func TestPriceListValidation(t *testing.T) {
	for name, xml := range map[string]string{
		"без merchantid": `<kaspi_catalog><offers/></kaspi_catalog>`,
		"без цены":       `<kaspi_catalog><merchantid>1</merchantid><offers><offer sku="A"><model>x</model></offer></offers></kaspi_catalog>`,
		"повтор sku":     `<kaspi_catalog><merchantid>1</merchantid><offers><offer sku="A"><price>1</price></offer><offer sku="A"><price>1</price></offer></offers></kaspi_catalog>`,
	} {
		if _, err := ParsePriceList(strings.NewReader(xml)); err == nil {
			t.Errorf("%s: ждали ошибку", name)
		}
	}
}

func attr(body map[string]any, key string) any {
	return body["data"].(map[string]any)["attributes"].(map[string]any)[key]
}

func (o Order) withWaybill() *Order {
	o.WaybillNumber, o.NumberOfSpace = "KD"+o.Code, 2
	return &o
}
