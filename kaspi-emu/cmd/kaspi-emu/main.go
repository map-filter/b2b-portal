package main

import (
	"context"
	"errors"
	"log/slog"
	"net/http"
	"os"
	"os/signal"
	"strconv"
	"syscall"
	"time"
	_ "time/tzdata"

	"github.com/b2b-portal/kaspi-emu/internal/kaspi"
)

func main() {
	slog.SetDefault(slog.New(slog.NewJSONHandler(os.Stdout, nil)))

	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()

	location, err := time.LoadLocation(env("KASPI_EMU_TIMEZONE", "Asia/Almaty"))
	if err != nil {
		slog.Error("часовой пояс", "err", err)
		os.Exit(1)
	}

	cfg := kaspi.Config{
		Token:         env("KASPI_EMU_TOKEN", "demo-kaspi-token"),
		PublicURL:     env("KASPI_EMU_PUBLIC_URL", "http://kaspi-emu:8080"),
		StateFile:     env("KASPI_EMU_STATE", "/data/state.json"),
		MaxRangeDays:  envInt("KASPI_EMU_MAX_RANGE_DAYS", 14),
		SignAfter:     envDuration("KASPI_EMU_SIGN_AFTER", 2*time.Minute),
		CourierAfter:  envDuration("KASPI_EMU_COURIER_AFTER", 3*time.Minute),
		AcceptTimeout: envDuration("KASPI_EMU_ACCEPT_TIMEOUT", 0),
		Location:      location,
		HistoryDays:   envInt("KASPI_EMU_HISTORY_DAYS", 60),
		OrdersPerDay:  envInt("KASPI_EMU_ORDERS_PER_DAY", 6),
	}
	store := kaspi.NewStore(cfg)

	mux := http.NewServeMux()
	kaspi.NewAPI(store, cfg).Register(mux)

	console := &kaspi.Console{Store: store}
	if url := os.Getenv("KASPI_EMU_PRICELIST_URL"); url != "" {
		console.Fetcher = &kaspi.Fetcher{URL: url, Store: store, Client: &http.Client{Timeout: 30 * time.Second}}
		go console.Fetcher.Run(ctx, envDuration("KASPI_EMU_PRICELIST_EVERY", time.Minute))
	}
	console.Register(mux)

	go every(ctx, 10*time.Second, store.Tick)
	if interval := envDuration("KASPI_EMU_NEW_ORDER_EVERY", 4*time.Minute); interval > 0 {
		go every(ctx, interval, func() {
			if o, err := store.NewOrder(); err == nil {
				slog.Info("новый заказ покупателя", "code", o.Code, "total", o.TotalPrice)
			}
		})
	}

	server := &http.Server{Addr: ":8080", Handler: logRequests(mux), ReadHeaderTimeout: 10 * time.Second}
	go func() {
		<-ctx.Done()
		shutdown, cancel := context.WithTimeout(context.Background(), 5*time.Second)
		defer cancel()
		_ = server.Shutdown(shutdown)
	}()

	slog.Info("эмулятор Kaspi API слушает :8080")
	if err := server.ListenAndServe(); err != nil && !errors.Is(err, http.ErrServerClosed) {
		slog.Error("http", "err", err)
		os.Exit(1)
	}
}

type statusWriter struct {
	http.ResponseWriter
	status int
}

func (w *statusWriter) WriteHeader(code int) {
	w.status = code
	w.ResponseWriter.WriteHeader(code)
}

func logRequests(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if len(r.URL.Path) < 9 || r.URL.Path[:9] != "/shop/api" {
			next.ServeHTTP(w, r)
			return
		}
		started := time.Now()
		sw := &statusWriter{ResponseWriter: w, status: http.StatusOK}
		next.ServeHTTP(sw, r)
		slog.Info("api", "method", r.Method, "path", r.URL.Path, "status", sw.status, "ms", time.Since(started).Milliseconds())
	})
}

func every(ctx context.Context, interval time.Duration, fn func()) {
	ticker := time.NewTicker(interval)
	defer ticker.Stop()
	for {
		select {
		case <-ctx.Done():
			return
		case <-ticker.C:
			fn()
		}
	}
}

func env(key, fallback string) string {
	if value := os.Getenv(key); value != "" {
		return value
	}
	return fallback
}

func envInt(key string, fallback int) int {
	if value, err := strconv.Atoi(os.Getenv(key)); err == nil {
		return value
	}
	return fallback
}

func envDuration(key string, fallback time.Duration) time.Duration {
	if value, err := time.ParseDuration(os.Getenv(key)); err == nil {
		return value
	}
	return fallback
}
