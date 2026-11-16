package kaspi

import (
	"encoding/base64"
	"strconv"
)

const (
	StateNew           = "NEW"
	StateSignRequired  = "SIGN_REQUIRED"
	StatePickup        = "PICKUP"
	StateDelivery      = "DELIVERY"
	StateKaspiDelivery = "KASPI_DELIVERY"
	StateArchive       = "ARCHIVE"

	StatusApproved        = "APPROVED_BY_BANK"
	StatusAccepted        = "ACCEPTED_BY_MERCHANT"
	StatusCompleted       = "COMPLETED"
	StatusCancelled       = "CANCELLED"
	StatusCancelling      = "CANCELLING"
	StatusReturnRequested = "KASPI_DELIVERY_RETURN_REQUESTED"
	StatusReturned        = "RETURNED"
	StatusArrived         = "ARRIVED"
	StatusAssemble        = "ASSEMBLE"

	ModeLocal          = "DELIVERY_LOCAL"
	ModePickup         = "DELIVERY_PICKUP"
	ModeRegionalToDoor = "DELIVERY_REGIONAL_TODOOR"
	ModeRegionalPickup = "DELIVERY_REGIONAL_PICKUP"

	PayCredit  = "PAY_WITH_CREDIT"
	PayPrepaid = "PREPAID"
)

var (
	States          = []string{StateNew, StateSignRequired, StatePickup, StateDelivery, StateKaspiDelivery, StateArchive}
	CancelReasons   = []string{"BUYER_CANCELLATION_BY_MERCHANT", "BUYER_NOT_REACHABLE", "MERCHANT_OUT_OF_STOCK"}
	finalStatuses   = map[string]bool{StatusCompleted: true, StatusCancelled: true, StatusReturned: true}
	deliveryTypeFor = map[string]string{ModePickup: "PICKUP", ModeRegionalPickup: "PICKUP", ModeLocal: "DELIVERY", ModeRegionalToDoor: "DELIVERY"}
)

type Person struct {
	FirstName string `json:"firstName"`
	LastName  string `json:"lastName"`
	CellPhone string `json:"cellPhone"`
}

type Address struct {
	StreetName       string   `json:"streetName"`
	StreetNumber     string   `json:"streetNumber"`
	Town             string   `json:"town"`
	District         *string  `json:"district"`
	Building         *string  `json:"building"`
	Apartment        *string  `json:"apartment"`
	FormattedAddress string   `json:"formattedAddress"`
	Latitude         *float64 `json:"latitude"`
	Longitude        *float64 `json:"longitude"`
}

type Entry struct {
	ID           string  `json:"id"`
	EntryNumber  int     `json:"entryNumber"`
	SKU          string  `json:"sku"`
	Name         string  `json:"name"`
	Brand        string  `json:"brand"`
	Category     string  `json:"category"`
	MasterID     string  `json:"masterId"`
	MerchantID   string  `json:"merchantProductId"`
	Quantity     int     `json:"quantity"`
	BasePrice    float64 `json:"basePrice"`
	TotalPrice   float64 `json:"totalPrice"`
	DeliveryCost float64 `json:"deliveryCost"`
	StoreID      string  `json:"storeId"`
	Weight       float64 `json:"weight"`
}

type Event struct {
	At   int64  `json:"at"`
	Text string `json:"text"`
}

type Order struct {
	ID                              string   `json:"id"`
	Code                            string   `json:"code"`
	CustomerID                      string   `json:"customerId"`
	Customer                        Person   `json:"customer"`
	TotalPrice                      float64  `json:"totalPrice"`
	PaymentMode                     string   `json:"paymentMode"`
	CreditTerm                      int      `json:"creditTerm,omitempty"`
	DeliveryMode                    string   `json:"deliveryMode"`
	IsKaspiDelivery                 bool     `json:"isKaspiDelivery"`
	Express                         bool     `json:"express"`
	PreOrder                        bool     `json:"preOrder"`
	SignatureRequired               bool     `json:"signatureRequired"`
	State                           string   `json:"state"`
	Status                          string   `json:"status"`
	CreationDate                    int64    `json:"creationDate"`
	ApprovedByBankDate              int64    `json:"approvedByBankDate"`
	PlannedDeliveryDate             int64    `json:"plannedDeliveryDate"`
	DeliveryCost                    float64  `json:"deliveryCost"`
	DeliveryCostForSeller           float64  `json:"deliveryCostForSeller"`
	DeliveryAddress                 *Address `json:"deliveryAddress,omitempty"`
	StoreID                         string   `json:"storeId"`
	CancellationReason              string   `json:"cancellationReason,omitempty"`
	CancellationComment             string   `json:"cancellationComment,omitempty"`
	NumberOfSpace                   int      `json:"numberOfSpace,omitempty"`
	WaybillNumber                   string   `json:"waybillNumber,omitempty"`
	CourierTransmissionPlanningDate int64    `json:"courierTransmissionPlanningDate,omitempty"`
	CourierTransmissionDate         int64    `json:"courierTransmissionDate,omitempty"`
	ReturnedToWarehouse             bool     `json:"returnedToWarehouse"`
	SecurityCode                    string   `json:"securityCode,omitempty"`
	Entries                         []Entry  `json:"entries"`
	Events                          []Event  `json:"events"`
	UpdatedAt                       int64    `json:"updatedAt"`
}

func (o *Order) Final() bool {
	return finalStatuses[o.Status]
}

func (o *Order) DeliveryType() string {
	if o.IsKaspiDelivery {
		return "DELIVERY"
	}
	return deliveryTypeFor[o.DeliveryMode]
}

func (o *Order) acceptedState() string {
	switch {
	case o.IsKaspiDelivery:
		return StateKaspiDelivery
	case o.DeliveryMode == ModePickup:
		return StatePickup
	default:
		return StateDelivery
	}
}

func encodeID(n int64) string {
	return base64.RawStdEncoding.EncodeToString([]byte(strconv.FormatInt(n, 10)))
}
