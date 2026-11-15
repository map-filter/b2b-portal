package exchange

import (
	"bytes"
	"context"
	"io"
	"net/http"
	"net/http/httptest"
	"os"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/b2b-portal/exchange/internal/commerceml"
)

type recorder struct {
	mu   sync.Mutex
	keys []string
}

func (r *recorder) Publish(_ context.Context, key string, _ any) error {
	r.mu.Lock()
	defer r.mu.Unlock()
	r.keys = append(r.keys, key)
	return nil
}

type fakeOrders struct {
	queue     []commerceml.OrderEvent
	pending   int
	confirmed int
}

func (f *fakeOrders) Fetch(int) ([]commerceml.OrderEvent, error) {
	f.pending = len(f.queue)
	return f.queue, nil
}

func (f *fakeOrders) Confirm() (int, error) {
	f.confirmed += f.pending
	f.queue, f.pending = nil, 0
	return f.confirmed, nil
}

type client struct {
	t       *testing.T
	handler *Handler
	server  *httptest.Server
	cookie  *http.Cookie
}

func (c *client) call(method, query string, body io.Reader) string {
	c.t.Helper()
	request, _ := http.NewRequest(method, c.server.URL+"/1c_exchange.php?"+query, body)
	request.SetBasicAuth("1c", "secret")
	if c.cookie != nil {
		request.AddCookie(c.cookie)
	}
	response, err := http.DefaultClient.Do(request)
	if err != nil {
		c.t.Fatal(err)
	}
	defer response.Body.Close()
	data, _ := io.ReadAll(response.Body)
	return string(data)
}

func newClient(t *testing.T, orders Orders) (*client, *recorder) {
	publisher := &recorder{}
	handler := NewHandler(Config{Login: "1c", Password: "secret", UploadDir: t.TempDir(), FileLimit: 1 << 20, BatchSize: 2}, publisher, orders)
	server := httptest.NewServer(handler.Routes())
	t.Cleanup(server.Close)
	return &client{t: t, server: server, handler: handler}, publisher
}

func (c *client) login(kind string) {
	lines := strings.Split(c.call("GET", "type="+kind+"&mode=checkauth", nil), "\n")
	if lines[0] != "success" {
		c.t.Fatalf("checkauth: %v", lines)
	}
	c.cookie = &http.Cookie{Name: lines[1], Value: lines[2]}
}

func TestCatalogExchangeFullCycle(t *testing.T) {
	c, publisher := newClient(t, &fakeOrders{})
	c.login("catalog")

	if got := c.call("GET", "type=catalog&mode=init", nil); !strings.HasPrefix(got, "zip=no") {
		t.Fatalf("init: %q", got)
	}

	content, _ := os.ReadFile("../commerceml/testdata/import.xml")
	half := len(content) / 2
	for _, part := range [][]byte{content[:half], content[half:]} {
		if got := c.call("POST", "type=catalog&mode=file&filename=import.xml", bytes.NewReader(part)); got != "success\n" {
			t.Fatalf("file: %q", got)
		}
	}

	if got := c.call("GET", "type=catalog&mode=import&filename=import.xml", nil); got != "success\n" {
		t.Fatalf("import: %q", got)
	}

	joined := strings.Join(publisher.keys, ",")
	want := "catalog.categories,catalog.products,catalog.products,catalog.import_finished"
	if joined != want {
		t.Errorf("публикации %s, ждали %s", joined, want)
	}
}

func TestRejectsWrongPasswordAndMissingSession(t *testing.T) {
	c, _ := newClient(t, &fakeOrders{})

	request, _ := http.NewRequest("GET", c.server.URL+"/1c_exchange.php?type=catalog&mode=checkauth", nil)
	request.SetBasicAuth("1c", "wrong")
	response, _ := http.DefaultClient.Do(request)
	body, _ := io.ReadAll(response.Body)
	if !strings.HasPrefix(string(body), "failure") {
		t.Errorf("неверный пароль принят: %q", body)
	}

	if got := c.call("GET", "type=catalog&mode=init", nil); !strings.HasPrefix(got, "failure") {
		t.Errorf("init без сессии: %q", got)
	}
}

func TestPathTraversalIsBlocked(t *testing.T) {
	c, _ := newClient(t, &fakeOrders{})
	c.login("catalog")

	c.call("POST", "type=catalog&mode=file&filename=../../etc/evil.xml", strings.NewReader("<x/>"))
	if got := c.call("GET", "type=catalog&mode=import&filename=../../etc/passwd", nil); !strings.HasPrefix(got, "failure") {
		t.Errorf("обход каталога не заблокирован: %q", got)
	}
}

func TestSaleQueryReturnsOrdersAndConfirms(t *testing.T) {
	orders := &fakeOrders{queue: []commerceml.OrderEvent{{
		Number:    "B2B-001001",
		CreatedAt: time.Date(2026, 11, 15, 6, 3, 0, 0, time.UTC),
		Total:     "1500.00",
		Company:   commerceml.OrderClient{Name: "Клиент", BIN: "123456789012"},
		Items:     []commerceml.OrderLine{{SKU: "DEMO-1", Name: "Товар", Unit: "шт", Quantity: 3, Price: "500.00", Total: "1500.00"}},
	}}}
	c, _ := newClient(t, orders)
	c.handler.cfg.Location = time.FixedZone("Asia/Almaty", 5*3600)
	c.login("sale")

	body := c.call("GET", "type=sale&mode=query", nil)
	for _, fragment := range []string{"<Номер>B2B-001001</Номер>", "<Время>11:03:00</Время>", "<ИНН>123456789012</ИНН>", "<Количество>3</Количество>", "Заказ товара"} {
		if !strings.Contains(body, fragment) {
			t.Errorf("в выгрузке нет %s", fragment)
		}
	}

	if got := c.call("GET", "type=sale&mode=success", nil); got != "success\n" || orders.confirmed != 1 {
		t.Errorf("success: %q, подтверждено %d", got, orders.confirmed)
	}
}
