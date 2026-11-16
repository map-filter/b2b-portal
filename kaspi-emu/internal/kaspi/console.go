package kaspi

import (
	"context"
	_ "embed"
	"fmt"
	"io"
	"log/slog"
	"net/http"
	"time"
)

//go:embed web/index.html
var consoleHTML []byte

type Fetcher struct {
	URL    string
	Store  *Store
	Client *http.Client
}

func (f *Fetcher) Load(ctx context.Context) error {
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, f.URL, nil)
	if err != nil {
		return err
	}
	resp, err := f.Client.Do(req)
	if err != nil {
		f.Store.PriceListFailed(err)
		return err
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		err := fmt.Errorf("прайс-лист: HTTP %d", resp.StatusCode)
		f.Store.PriceListFailed(err)
		return err
	}
	list, err := ParsePriceList(io.LimitReader(resp.Body, 64<<20))
	if err != nil {
		f.Store.PriceListFailed(err)
		return err
	}
	f.Store.SetPriceList(list, f.URL)
	return nil
}

func (f *Fetcher) Run(ctx context.Context, every time.Duration) {
	retry := 5 * time.Second
	for {
		wait := every
		if err := f.Load(ctx); err != nil {
			slog.Warn("прайс-лист не загружен", "url", f.URL, "err", err)
			wait = min(retry, every)
			retry = min(retry*2, every)
		} else {
			retry = 5 * time.Second
		}
		select {
		case <-ctx.Done():
			return
		case <-time.After(wait):
		}
	}
}

type Console struct {
	Store   *Store
	Fetcher *Fetcher
}

func (c *Console) Register(mux *http.ServeMux) {
	mux.HandleFunc("GET /{$}", func(w http.ResponseWriter, r *http.Request) {
		http.Redirect(w, r, "/emu/", http.StatusFound)
	})
	mux.HandleFunc("GET /emu/{$}", func(w http.ResponseWriter, _ *http.Request) {
		w.Header().Set("Content-Type", "text/html; charset=utf-8")
		_, _ = w.Write(consoleHTML)
	})
	mux.HandleFunc("GET /emu/api/state", func(w http.ResponseWriter, _ *http.Request) {
		writeJSON(w, http.StatusOK, c.Store.Snapshot(60))
	})
	mux.HandleFunc("POST /emu/api/orders", func(w http.ResponseWriter, _ *http.Request) {
		o, err := c.Store.NewOrder()
		c.reply(w, map[string]string{"code": o.Code}, err)
	})
	mux.HandleFunc("POST /emu/api/orders/{code}/cancel", func(w http.ResponseWriter, r *http.Request) {
		c.reply(w, nil, c.Store.BuyerCancel(r.PathValue("code")))
	})
	mux.HandleFunc("POST /emu/api/orders/{code}/return", func(w http.ResponseWriter, r *http.Request) {
		c.reply(w, nil, c.Store.RequestReturn(r.PathValue("code")))
	})
	mux.HandleFunc("POST /emu/api/orders/{code}/returned", func(w http.ResponseWriter, r *http.Request) {
		c.reply(w, nil, c.Store.CompleteReturn(r.PathValue("code")))
	})
	mux.HandleFunc("POST /emu/api/pricelist/reload", func(w http.ResponseWriter, r *http.Request) {
		if c.Fetcher == nil {
			c.reply(w, nil, fmt.Errorf("адрес прайс-листа не задан"))
			return
		}
		c.reply(w, nil, c.Fetcher.Load(r.Context()))
	})
	mux.HandleFunc("POST /emu/api/pricelist", func(w http.ResponseWriter, r *http.Request) {
		list, err := ParsePriceList(io.LimitReader(r.Body, 64<<20))
		if err == nil {
			c.Store.SetPriceList(list, "загружен вручную")
		}
		c.reply(w, nil, err)
	})
	mux.HandleFunc("POST /emu/api/reset", func(w http.ResponseWriter, _ *http.Request) {
		c.Store.Reset()
		c.reply(w, nil, nil)
	})
	mux.HandleFunc("GET /health", func(w http.ResponseWriter, _ *http.Request) {
		writeJSON(w, http.StatusOK, map[string]string{"status": "ok"})
	})
}

func (c *Console) reply(w http.ResponseWriter, body any, err error) {
	if err != nil {
		writeJSON(w, http.StatusUnprocessableEntity, map[string]string{"error": err.Error()})
		return
	}
	if body == nil {
		body = map[string]bool{"ok": true}
	}
	writeJSON(w, http.StatusOK, body)
}
