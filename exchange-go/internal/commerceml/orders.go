package commerceml

import (
	"encoding/xml"
	"io"
	"time"
)

type OrderEvent struct {
	Number    string      `json:"number"`
	CreatedAt time.Time   `json:"created_at"`
	Total     string      `json:"total"`
	Comment   string      `json:"comment"`
	Contract  string      `json:"contract"`
	Company   OrderClient `json:"company"`
	Items     []OrderLine `json:"items"`
}

type OrderClient struct {
	ExternalID string `json:"external_id"`
	Name       string `json:"name"`
	LegalName  string `json:"legal_name"`
	BIN        string `json:"bin"`
	Address    string `json:"address"`
}

type OrderLine struct {
	ProductExternalID string `json:"product_external_id"`
	SKU               string `json:"sku"`
	Name              string `json:"name"`
	Unit              string `json:"unit"`
	Quantity          int    `json:"quantity"`
	Price             string `json:"price"`
	Total             string `json:"total"`
}

type xmlDocuments struct {
	XMLName   xml.Name      `xml:"КоммерческаяИнформация"`
	Version   string        `xml:"ВерсияСхемы,attr"`
	CreatedAt string        `xml:"ДатаФормирования,attr"`
	Documents []xmlDocument `xml:"Документ"`
}

type xmlDocument struct {
	ID         string         `xml:"Ид"`
	Number     string         `xml:"Номер"`
	Date       string         `xml:"Дата"`
	Time       string         `xml:"Время"`
	Operation  string         `xml:"ХозОперация"`
	Role       string         `xml:"Роль"`
	Currency   string         `xml:"Валюта"`
	Rate       string         `xml:"Курс"`
	Sum        string         `xml:"Сумма"`
	Comment    string         `xml:"Комментарий,omitempty"`
	Clients    []xmlClient    `xml:"Контрагенты>Контрагент"`
	Products   []xmlOrderLine `xml:"Товары>Товар"`
	Requisites []xmlRequisite `xml:"ЗначенияРеквизитов>ЗначениеРеквизита"`
}

type xmlClient struct {
	ID       string `xml:"Ид"`
	Name     string `xml:"Наименование"`
	FullName string `xml:"ПолноеНаименование"`
	Role     string `xml:"Роль"`
	BIN      string `xml:"ИНН"`
	Address  string `xml:"АдресРегистрации>Представление,omitempty"`
}

type xmlOrderLine struct {
	ID       string `xml:"Ид"`
	SKU      string `xml:"Артикул"`
	Name     string `xml:"Наименование"`
	Unit     string `xml:"БазоваяЕдиница"`
	Price    string `xml:"ЦенаЗаЕдиницу"`
	Quantity int    `xml:"Количество"`
	Sum      string `xml:"Сумма"`
}

type xmlRequisite struct {
	Name  string `xml:"Наименование"`
	Value string `xml:"Значение"`
}

func WriteOrders(w io.Writer, orders []OrderEvent, now time.Time, location *time.Location) error {
	document := xmlDocuments{Version: "2.08", CreatedAt: now.In(location).Format("2006-01-02T15:04:05")}

	for _, order := range orders {
		created := order.CreatedAt.In(location)
		clientID := order.Company.ExternalID
		if clientID == "" {
			clientID = "BIN-" + order.Company.BIN
		}

		doc := xmlDocument{
			ID:        order.Number,
			Number:    order.Number,
			Date:      created.Format("2006-01-02"),
			Time:      created.Format("15:04:05"),
			Operation: "Заказ товара",
			Role:      "Продавец",
			Currency:  "KZT",
			Rate:      "1",
			Sum:       order.Total,
			Comment:   order.Comment,
			Clients: []xmlClient{{
				ID:       clientID,
				Name:     order.Company.Name,
				FullName: order.Company.LegalName,
				Role:     "Покупатель",
				BIN:      order.Company.BIN,
				Address:  order.Company.Address,
			}},
			Requisites: []xmlRequisite{
				{Name: "Статус заказа", Value: "[N] Принят"},
				{Name: "Договор", Value: order.Contract},
				{Name: "Источник", Value: "B2B-портал"},
			},
		}

		for _, line := range order.Items {
			id := line.ProductExternalID
			if id == "" {
				id = line.SKU
			}
			doc.Products = append(doc.Products, xmlOrderLine{
				ID: id, SKU: line.SKU, Name: line.Name, Unit: line.Unit,
				Price: line.Price, Quantity: line.Quantity, Sum: line.Total,
			})
		}

		document.Documents = append(document.Documents, doc)
	}

	if _, err := io.WriteString(w, xml.Header); err != nil {
		return err
	}
	encoder := xml.NewEncoder(w)
	encoder.Indent("", "  ")
	return encoder.Encode(document)
}
