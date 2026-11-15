package exchange

import (
	"context"

	"github.com/b2b-portal/exchange/internal/commerceml"
)

type Publisher interface {
	Publish(ctx context.Context, routingKey string, payload any) error
}

type Batch[T any] struct {
	ImportID string `json:"import_id"`
	Items    []T    `json:"items"`
}

type publishingSink struct {
	ctx       context.Context
	publisher Publisher
	importID  string
}

func publish[T any](s *publishingSink, key string, items []T) error {
	return s.publisher.Publish(s.ctx, key, Batch[T]{ImportID: s.importID, Items: items})
}

func (s *publishingSink) Categories(v []commerceml.Category) error {
	return publish(s, "catalog.categories", v)
}
func (s *publishingSink) Products(v []commerceml.Product) error {
	return publish(s, "catalog.products", v)
}
func (s *publishingSink) PriceTypes(v []commerceml.PriceType) error {
	return publish(s, "catalog.price_types", v)
}
func (s *publishingSink) Warehouses(v []commerceml.Warehouse) error {
	return publish(s, "catalog.warehouses", v)
}
func (s *publishingSink) Prices(v []commerceml.Price) error { return publish(s, "catalog.prices", v) }
func (s *publishingSink) Stocks(v []commerceml.Stock) error { return publish(s, "catalog.stocks", v) }
