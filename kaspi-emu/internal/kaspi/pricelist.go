package kaspi

import (
	"encoding/xml"
	"errors"
	"fmt"
	"io"
	"strconv"
	"strings"
)

type Availability struct {
	Available  string `xml:"available,attr"`
	StoreID    string `xml:"storeId,attr"`
	PreOrder   string `xml:"preOrder,attr"`
	StockCount string `xml:"stockCount,attr"`
}

type CityPrice struct {
	CityID string `xml:"cityId,attr"`
	Value  string `xml:",chardata"`
}

type Offer struct {
	SKU            string         `xml:"sku,attr" json:"sku"`
	Model          string         `xml:"model" json:"model"`
	Brand          string         `xml:"brand" json:"brand"`
	Availabilities []Availability `xml:"availabilities>availability" json:"availabilities"`
	Price          string         `xml:"price" json:"-"`
	CityPrices     []CityPrice    `xml:"cityprices>cityprice" json:"-"`
	Value          float64        `xml:"-" json:"price"`
}

type PriceList struct {
	XMLName    xml.Name `xml:"kaspi_catalog"`
	Date       string   `xml:"date,attr"`
	Company    string   `xml:"company"`
	MerchantID string   `xml:"merchantid"`
	Offers     []Offer  `xml:"offers>offer"`
}

func ParsePriceList(r io.Reader) (*PriceList, error) {
	var list PriceList
	if err := xml.NewDecoder(r).Decode(&list); err != nil {
		return nil, fmt.Errorf("прайс-лист не разобран: %w", err)
	}
	if list.MerchantID == "" {
		return nil, errors.New("в прайс-листе нет merchantid")
	}

	seen := map[string]bool{}
	for i := range list.Offers {
		offer := &list.Offers[i]
		if offer.SKU == "" {
			return nil, fmt.Errorf("предложение %d без sku", i+1)
		}
		if seen[offer.SKU] {
			return nil, fmt.Errorf("sku %s повторяется", offer.SKU)
		}
		seen[offer.SKU] = true

		raw := offer.Price
		if raw == "" && len(offer.CityPrices) > 0 {
			raw = offer.CityPrices[0].Value
		}
		price, err := strconv.ParseFloat(strings.TrimSpace(raw), 64)
		if err != nil || price <= 0 {
			return nil, fmt.Errorf("sku %s: цена %q", offer.SKU, raw)
		}
		offer.Value = price
	}
	return &list, nil
}

func (o Offer) Stock(storeID string) (int, bool) {
	for _, a := range o.Availabilities {
		if storeID != "" && a.StoreID != storeID {
			continue
		}
		if a.Available != "yes" {
			continue
		}
		if a.StockCount == "" {
			return 99, true
		}
		count, err := strconv.Atoi(a.StockCount)
		if err != nil || count <= 0 {
			continue
		}
		return count, true
	}
	return 0, false
}

func (o Offer) AnyStore() (string, int) {
	for _, a := range o.Availabilities {
		if count, ok := o.Stock(a.StoreID); ok {
			return a.StoreID, count
		}
	}
	return "", 0
}
