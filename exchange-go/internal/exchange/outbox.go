package exchange

import (
	"encoding/json"
	"log/slog"
	"sync"

	amqp "github.com/rabbitmq/amqp091-go"

	"github.com/b2b-portal/exchange/internal/broker"
	"github.com/b2b-portal/exchange/internal/commerceml"
)

// OrderOutbox отдаёт заказы в 1С и подтверждает их в очереди только после mode=success.
type OrderOutbox struct {
	channel *amqp.Channel
	mu      sync.Mutex
	pending []uint64
}

func NewOrderOutbox(channel *amqp.Channel) *OrderOutbox {
	return &OrderOutbox{channel: channel}
}

func (o *OrderOutbox) Fetch(limit int) ([]commerceml.OrderEvent, error) {
	o.mu.Lock()
	defer o.mu.Unlock()

	if err := o.releasePending(); err != nil {
		return nil, err
	}

	var orders []commerceml.OrderEvent
	for len(orders) < limit {
		delivery, ok, err := o.channel.Get(broker.OrdersQueue, false)
		if err != nil {
			return nil, err
		}
		if !ok {
			break
		}

		var order commerceml.OrderEvent
		if err := json.Unmarshal(delivery.Body, &order); err != nil {
			slog.Error("битое сообщение заказа, отбрасываю", "err", err)
			_ = delivery.Nack(false, false)
			continue
		}

		o.pending = append(o.pending, delivery.DeliveryTag)
		orders = append(orders, order)
	}

	return orders, nil
}

func (o *OrderOutbox) Confirm() (int, error) {
	o.mu.Lock()
	defer o.mu.Unlock()

	count := len(o.pending)
	for _, tag := range o.pending {
		if err := o.channel.Ack(tag, false); err != nil {
			return 0, err
		}
	}
	o.pending = nil
	return count, nil
}

func (o *OrderOutbox) releasePending() error {
	for _, tag := range o.pending {
		if err := o.channel.Nack(tag, false, true); err != nil {
			return err
		}
	}
	o.pending = nil
	return nil
}
