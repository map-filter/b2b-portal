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

	"github.com/b2b-portal/exchange/internal/broker"
	"github.com/b2b-portal/exchange/internal/exchange"
)

func main() {
	slog.SetDefault(slog.New(slog.NewJSONHandler(os.Stdout, nil)))

	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()

	mq, err := broker.Connect(ctx, env("RABBITMQ_URL", "amqp://guest:guest@rabbitmq:5672/"))
	if err != nil {
		slog.Error("старт невозможен", "err", err)
		os.Exit(1)
	}
	defer mq.Close()

	ordersChannel, err := mq.Channel()
	if err != nil {
		slog.Error("канал заказов", "err", err)
		os.Exit(1)
	}

	location, err := time.LoadLocation(env("EXCHANGE_TIMEZONE", "Asia/Almaty"))
	if err != nil {
		slog.Error("часовой пояс", "err", err)
		os.Exit(1)
	}

	handler := exchange.NewHandler(exchange.Config{
		Login:     env("EXCHANGE_LOGIN", "1c"),
		Password:  env("EXCHANGE_PASSWORD", "1c"),
		UploadDir: env("EXCHANGE_UPLOAD_DIR", os.TempDir()+"/1c"),
		FileLimit: int64(envInt("EXCHANGE_FILE_LIMIT", 200<<20)),
		BatchSize: envInt("EXCHANGE_BATCH_SIZE", 500),
		Location:  location,
	}, mq, exchange.NewOrderOutbox(ordersChannel))

	server := &http.Server{Addr: ":8080", Handler: handler.Routes(), ReadHeaderTimeout: 10 * time.Second}

	go func() {
		select {
		case <-ctx.Done():
		case err := <-mq.Closed():
			slog.Error("соединение с rabbitmq потеряно, выхожу для перезапуска", "err", err)
			stop()
		}
		shutdown, cancel := context.WithTimeout(context.Background(), 10*time.Second)
		defer cancel()
		_ = server.Shutdown(shutdown)
	}()

	slog.Info("обмен с 1С слушает :8080")
	if err := server.ListenAndServe(); err != nil && !errors.Is(err, http.ErrServerClosed) {
		slog.Error("http", "err", err)
		os.Exit(1)
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
