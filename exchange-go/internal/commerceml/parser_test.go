package commerceml

import (
	"os"
	"testing"
)

type memorySink struct {
	categories []Category
	products   []Product
	priceTypes []PriceType
	warehouses []Warehouse
	prices     []Price
	stocks     []Stock
	batches    int
}

func (m *memorySink) Categories(v []Category) error {
	m.categories = append(m.categories, v...)
	return nil
}
func (m *memorySink) PriceTypes(v []PriceType) error {
	m.priceTypes = append(m.priceTypes, v...)
	return nil
}
func (m *memorySink) Warehouses(v []Warehouse) error {
	m.warehouses = append(m.warehouses, v...)
	return nil
}
func (m *memorySink) Prices(v []Price) error { m.prices = append(m.prices, v...); return nil }
func (m *memorySink) Stocks(v []Stock) error { m.stocks = append(m.stocks, v...); return nil }
func (m *memorySink) Products(v []Product) error {
	m.batches++
	m.products = append(m.products, v...)
	return nil
}

func parseFile(t *testing.T, name string, batch int) *memorySink {
	t.Helper()
	file, err := os.Open("testdata/" + name)
	if err != nil {
		t.Fatal(err)
	}
	defer file.Close()

	sink := &memorySink{}
	if err := NewParser(sink, batch).Parse(file); err != nil {
		t.Fatal(err)
	}
	return sink
}

func TestImportParsesTreeProductsAndSpecs(t *testing.T) {
	sink := parseFile(t, "import.xml", 2)

	if len(sink.categories) != 3 {
		t.Fatalf("категорий %d, ждали 3", len(sink.categories))
	}
	if sink.categories[1].ParentID != "g-cable" {
		t.Errorf("родитель вложенной группы %q", sink.categories[1].ParentID)
	}
	if len(sink.products) != 3 || sink.batches != 2 {
		t.Fatalf("товаров %d пачками %d, ждали 3 и 2", len(sink.products), sink.batches)
	}

	cable := sink.products[0]
	if cable.SKU != "EXT-001" || cable.Unit != "м" || cable.CategoryID != "g-power" || cable.Brand != "КабельСтандарт" {
		t.Errorf("кабель разобран неверно: %+v", cable)
	}
	if cable.Specs["Сечение"] != "3x2.5" {
		t.Errorf("свойство не подставилось: %v", cable.Specs)
	}
	if !sink.products[1].MarkedDelete || sink.products[2].MarkedDelete {
		t.Error("пометка удаления определена неверно")
	}
	if sink.products[2].Unit != "шт" {
		t.Errorf("единица по умолчанию %q", sink.products[2].Unit)
	}
}

func TestOffersParsesPricesAndBothStockFormats(t *testing.T) {
	sink := parseFile(t, "offers.xml", 500)

	if len(sink.priceTypes) != 2 || len(sink.warehouses) != 1 {
		t.Fatalf("типов цен %d, складов %d", len(sink.priceTypes), len(sink.warehouses))
	}
	if len(sink.prices) != 3 {
		t.Fatalf("цен %d, ждали 3", len(sink.prices))
	}
	if sink.prices[0].Value != "640.50" || sink.prices[1].Value != "1563.20" {
		t.Errorf("числа не нормализованы: %v", sink.prices[:2])
	}
	if sink.prices[2].ProductID != "g7h8" {
		t.Errorf("характеристика не отрезана от Ид: %q", sink.prices[2].ProductID)
	}
	if len(sink.stocks) != 2 || sink.stocks[0].Quantity != "1200" || sink.stocks[1].Quantity != "15" {
		t.Errorf("остатки: %+v", sink.stocks)
	}
}

func TestWindows1251(t *testing.T) {
	sink := parseFile(t, "import_cp1251.xml", 10)

	if len(sink.products) != 1 || sink.products[0].Name != "Розетка двойная" {
		t.Fatalf("cp1251 не прочитан: %+v", sink.products)
	}
}
