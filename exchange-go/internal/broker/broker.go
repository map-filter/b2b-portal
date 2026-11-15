package broker

import (
	"context"
	"encoding/json"
	"fmt"
	"log/slog"
	"sync"
	"time"

	amqp "github.com/rabbitmq/amqp091-go"
)

const (
	CatalogExchange = "b2b.catalog"
	EventsExchange  = "b2b.events"
	OrdersQueue     = "exchange.orders_to_1c"
)

type Broker struct {
	conn    *amqp.Connection
	publish *amqp.Channel
	confirm chan amqp.Confirmation
	mu      sync.Mutex
}

func Connect(ctx context.Context, url string) (*Broker, error) {
	var conn *amqp.Connection
	var err error
	for attempt := 1; ; attempt++ {
		conn, err = amqp.Dial(url)
		if err == nil {
			break
		}
		if attempt == 30 {
			return nil, fmt.Errorf("rabbitmq недоступен: %w", err)
		}
		slog.Warn("rabbitmq не отвечает, повтор", "attempt", attempt)
		select {
		case <-ctx.Done():
			return nil, ctx.Err()
		case <-time.After(2 * time.Second):
		}
	}

	channel, err := conn.Channel()
	if err != nil {
		return nil, err
	}
	if err := declare(channel); err != nil {
		return nil, err
	}
	if err := channel.Confirm(false); err != nil {
		return nil, err
	}

	return &Broker{
		conn:    conn,
		publish: channel,
		confirm: channel.NotifyPublish(make(chan amqp.Confirmation, 1)),
	}, nil
}

func declare(channel *amqp.Channel) error {
	for _, name := range []string{CatalogExchange, EventsExchange} {
		if err := channel.ExchangeDeclare(name, "topic", true, false, false, false, nil); err != nil {
			return err
		}
	}
	if _, err := channel.QueueDeclare(OrdersQueue, true, false, false, false, nil); err != nil {
		return err
	}
	return channel.QueueBind(OrdersQueue, "order.created", EventsExchange, false, nil)
}

// Publish ждёт подтверждения брокера, чтобы пачка каталога не потерялась при обрыве.
func (b *Broker) Publish(ctx context.Context, routingKey string, payload any) error {
	body, err := json.Marshal(payload)
	if err != nil {
		return err
	}

	b.mu.Lock()
	defer b.mu.Unlock()

	err = b.publish.PublishWithContext(ctx, CatalogExchange, routingKey, false, false, amqp.Publishing{
		ContentType:  "application/json",
		DeliveryMode: amqp.Persistent,
		Timestamp:    time.Now(),
		Body:         body,
	})
	if err != nil {
		return err
	}

	select {
	case confirmation := <-b.confirm:
		if !confirmation.Ack {
			return fmt.Errorf("брокер не принял сообщение %s", routingKey)
		}
		return nil
	case <-ctx.Done():
		return ctx.Err()
	}
}

func (b *Broker) Channel() (*amqp.Channel, error) {
	return b.conn.Channel()
}

func (b *Broker) Closed() <-chan *amqp.Error {
	return b.conn.NotifyClose(make(chan *amqp.Error, 1))
}

func (b *Broker) Close() error {
	return b.conn.Close()
}
