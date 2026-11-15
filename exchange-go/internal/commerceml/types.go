package commerceml

type Category struct {
	ExternalID string `json:"external_id"`
	ParentID   string `json:"parent_external_id,omitempty"`
	Name       string `json:"name"`
}

type Product struct {
	ExternalID   string            `json:"external_id"`
	SKU          string            `json:"sku"`
	Name         string            `json:"name"`
	CategoryID   string            `json:"category_external_id"`
	Unit         string            `json:"unit"`
	Description  string            `json:"description,omitempty"`
	Brand        string            `json:"brand,omitempty"`
	Specs        map[string]string `json:"specs"`
	MarkedDelete bool              `json:"deleted"`
}

type PriceType struct {
	ExternalID string `json:"external_id"`
	Name       string `json:"name"`
	Currency   string `json:"currency"`
}

type Warehouse struct {
	ExternalID string `json:"external_id"`
	Name       string `json:"name"`
}

type Price struct {
	ProductID   string `json:"product_external_id"`
	PriceTypeID string `json:"price_type_external_id"`
	Value       string `json:"price"`
}

type Stock struct {
	ProductID   string `json:"product_external_id"`
	WarehouseID string `json:"warehouse_external_id"`
	Quantity    string `json:"quantity"`
}

// Sink получает разобранные сущности по мере чтения файла, не дожидаясь конца документа.
type Sink interface {
	Categories([]Category) error
	Products([]Product) error
	PriceTypes([]PriceType) error
	Warehouses([]Warehouse) error
	Prices([]Price) error
	Stocks([]Stock) error
}

type Stats struct {
	Categories int `json:"categories"`
	Products   int `json:"products"`
	PriceTypes int `json:"price_types"`
	Warehouses int `json:"warehouses"`
	Prices     int `json:"prices"`
	Stocks     int `json:"stocks"`
}
