package commerceml

import (
	"encoding/xml"
	"errors"
	"fmt"
	"io"
	"strings"
)

type xmlGroup struct {
	ID     string     `xml:"Ид"`
	Name   string     `xml:"Наименование"`
	Groups []xmlGroup `xml:"Группы>Группа"`
}

type xmlProduct struct {
	ID          string   `xml:"Ид"`
	SKU         string   `xml:"Артикул"`
	Name        string   `xml:"Наименование"`
	Unit        xmlUnit  `xml:"БазоваяЕдиница"`
	Groups      []string `xml:"Группы>Ид"`
	Description string   `xml:"Описание"`
	Status      string   `xml:"Статус,attr"`
	Brand       string   `xml:"Изготовитель>Наименование"`
	Properties  []struct {
		ID    string `xml:"Ид"`
		Value string `xml:"Значение"`
	} `xml:"ЗначенияСвойств>ЗначенияСвойства"`
	Requisites []struct {
		Name  string `xml:"Наименование"`
		Value string `xml:"Значение"`
	} `xml:"ЗначенияРеквизитов>ЗначениеРеквизита"`
}

type xmlUnit struct {
	Short string `xml:"НаименованиеПолное,attr"`
	Name  string `xml:",chardata"`
}

type xmlProperty struct {
	ID   string `xml:"Ид"`
	Name string `xml:"Наименование"`
}

type xmlPriceType struct {
	ID       string `xml:"Ид"`
	Name     string `xml:"Наименование"`
	Currency string `xml:"Валюта"`
}

type xmlWarehouse struct {
	ID   string `xml:"Ид"`
	Name string `xml:"Наименование"`
}

type xmlOffer struct {
	ID     string `xml:"Ид"`
	Prices []struct {
		TypeID string `xml:"ИдТипаЦены"`
		Value  string `xml:"ЦенаЗаЕдиницу"`
	} `xml:"Цены>Цена"`
	LegacyStocks []struct {
		WarehouseID string `xml:"ИдСклада,attr"`
		Quantity    string `xml:"КоличествоНаСкладе,attr"`
	} `xml:"Склад"`
	Stocks []struct {
		WarehouseID string `xml:"Склад>Ид"`
		Quantity    string `xml:"Склад>Количество"`
	} `xml:"Остатки>Остаток"`
}

// Parser читает import.xml и offers.xml потоково: в памяти только текущий элемент и пачка для отправки.
type Parser struct {
	sink       Sink
	batchSize  int
	properties map[string]string
	stats      Stats
	products   []Product
	prices     []Price
	stocks     []Stock
}

func NewParser(sink Sink, batchSize int) *Parser {
	if batchSize <= 0 {
		batchSize = 500
	}
	return &Parser{sink: sink, batchSize: batchSize, properties: map[string]string{}}
}

func (p *Parser) Stats() Stats { return p.stats }

func (p *Parser) Parse(r io.Reader) error {
	decoder := xml.NewDecoder(r)
	decoder.CharsetReader = charsetReader

	for {
		token, err := decoder.Token()
		if errors.Is(err, io.EOF) {
			return p.flush()
		}
		if err != nil {
			return fmt.Errorf("xml: %w", err)
		}

		start, ok := token.(xml.StartElement)
		if !ok {
			continue
		}

		if err := p.handle(decoder, start); err != nil {
			return err
		}
	}
}

func (p *Parser) handle(decoder *xml.Decoder, start xml.StartElement) error {
	switch start.Name.Local {
	case "Классификатор":
		return nil
	case "Группы":
		return p.groups(decoder, start)
	case "Свойство":
		var property xmlProperty
		if err := decoder.DecodeElement(&property, &start); err != nil {
			return err
		}
		p.properties[property.ID] = property.Name
	case "Товар":
		var item xmlProduct
		if err := decoder.DecodeElement(&item, &start); err != nil {
			return err
		}
		return p.addProduct(item)
	case "ТипЦены":
		var item xmlPriceType
		if err := decoder.DecodeElement(&item, &start); err != nil {
			return err
		}
		p.stats.PriceTypes++
		return p.sink.PriceTypes([]PriceType{{ExternalID: item.ID, Name: item.Name, Currency: item.Currency}})
	case "Склад":
		var item xmlWarehouse
		if err := decoder.DecodeElement(&item, &start); err != nil {
			return err
		}
		if item.ID == "" {
			return nil
		}
		p.stats.Warehouses++
		return p.sink.Warehouses([]Warehouse{{ExternalID: item.ID, Name: item.Name}})
	case "Предложение":
		var item xmlOffer
		if err := decoder.DecodeElement(&item, &start); err != nil {
			return err
		}
		return p.addOffer(item)
	}
	return nil
}

func (p *Parser) groups(decoder *xml.Decoder, start xml.StartElement) error {
	var wrapper struct {
		Groups []xmlGroup `xml:"Группа"`
	}
	if err := decoder.DecodeElement(&wrapper, &start); err != nil {
		return err
	}

	var categories []Category
	var walk func(groups []xmlGroup, parent string)
	walk = func(groups []xmlGroup, parent string) {
		for _, group := range groups {
			categories = append(categories, Category{ExternalID: group.ID, ParentID: parent, Name: strings.TrimSpace(group.Name)})
			walk(group.Groups, group.ID)
		}
	}
	walk(wrapper.Groups, "")

	if len(categories) == 0 {
		return nil
	}
	p.stats.Categories += len(categories)
	return p.sink.Categories(categories)
}

func (p *Parser) addProduct(item xmlProduct) error {
	product := Product{
		ExternalID:   item.ID,
		SKU:          strings.TrimSpace(item.SKU),
		Name:         strings.TrimSpace(item.Name),
		Unit:         strings.TrimSpace(item.Unit.Name),
		Description:  strings.TrimSpace(item.Description),
		Brand:        strings.TrimSpace(item.Brand),
		Specs:        map[string]string{},
		MarkedDelete: item.Status == "Удален",
	}
	if len(item.Groups) > 0 {
		product.CategoryID = item.Groups[0]
	}
	if product.Unit == "" {
		product.Unit = "шт"
	}
	for _, value := range item.Properties {
		if name, ok := p.properties[value.ID]; ok && value.Value != "" {
			product.Specs[name] = value.Value
		}
	}
	for _, requisite := range item.Requisites {
		if requisite.Name == "ПометкаУдаления" && requisite.Value == "true" {
			product.MarkedDelete = true
		}
	}

	p.stats.Products++
	p.products = append(p.products, product)
	if len(p.products) >= p.batchSize {
		return p.flushProducts()
	}
	return nil
}

func (p *Parser) addOffer(item xmlOffer) error {
	productID, _, _ := strings.Cut(item.ID, "#")

	for _, price := range item.Prices {
		p.prices = append(p.prices, Price{ProductID: productID, PriceTypeID: price.TypeID, Value: normalizeNumber(price.Value)})
	}
	for _, stock := range item.LegacyStocks {
		p.stocks = append(p.stocks, Stock{ProductID: productID, WarehouseID: stock.WarehouseID, Quantity: normalizeNumber(stock.Quantity)})
	}
	for _, stock := range item.Stocks {
		p.stocks = append(p.stocks, Stock{ProductID: productID, WarehouseID: stock.WarehouseID, Quantity: normalizeNumber(stock.Quantity)})
	}

	if len(p.prices) >= p.batchSize {
		if err := p.flushPrices(); err != nil {
			return err
		}
	}
	if len(p.stocks) >= p.batchSize {
		return p.flushStocks()
	}
	return nil
}

func (p *Parser) flush() error {
	if err := p.flushProducts(); err != nil {
		return err
	}
	if err := p.flushPrices(); err != nil {
		return err
	}
	return p.flushStocks()
}

func (p *Parser) flushProducts() error {
	if len(p.products) == 0 {
		return nil
	}
	batch := p.products
	p.products = nil
	return p.sink.Products(batch)
}

func (p *Parser) flushPrices() error {
	if len(p.prices) == 0 {
		return nil
	}
	batch := p.prices
	p.stats.Prices += len(batch)
	p.prices = nil
	return p.sink.Prices(batch)
}

func (p *Parser) flushStocks() error {
	if len(p.stocks) == 0 {
		return nil
	}
	batch := p.stocks
	p.stats.Stocks += len(batch)
	p.stocks = nil
	return p.sink.Stocks(batch)
}

func normalizeNumber(value string) string {
	value = strings.ReplaceAll(strings.TrimSpace(value), " ", "")
	value = strings.ReplaceAll(value, " ", "")
	return strings.ReplaceAll(value, ",", ".")
}
