package kaspi

import (
	"fmt"
	"hash/fnv"
	"math"
	"strings"
	"time"
)

var (
	maleNames   = []string{"Ерлан", "Нурлан", "Арман", "Тимур", "Марат", "Сергей", "Бауыржан", "Дмитрий", "Ринат", "Азамат"}
	femaleNames = []string{"Айгерим", "Асель", "Дана", "Сауле", "Алия", "Ольга", "Жанар", "Мадина", "Гульнара", "Камила"}
	lastNames   = []string{"Сапаров", "Ахметов", "Иванов", "Нургалиев", "Ким", "Жумабаев", "Петров", "Касымов", "Ермеков", "Смагулов", "Ли", "Беков", "Оспанов", "Ковалёв", "Тлеуов"}
	streets    = []struct{ town, street string }{
		{"г. Павлодар", "улица Академика Сатпаева"},
		{"г. Павлодар", "улица Толстого"},
		{"г. Павлодар", "проспект Назарбаева"},
		{"г. Павлодар", "улица Ломова"},
		{"г. Павлодар", "улица Кутузова"},
		{"г. Экибастуз", "улица Ауэзова"},
		{"г. Аксу", "улица Энергетиков"},
	}
	stores = map[string]Address{
		"PP1": {StreetName: "улица Демо-Промышленная", StreetNumber: "1", Town: "г. Павлодар", FormattedAddress: "г. Павлодар, улица Демо-Промышленная, 1"},
		"PP2": {StreetName: "улица Торайгырова", StreetNumber: "64", Town: "г. Павлодар", FormattedAddress: "г. Павлодар, улица Торайгырова, 64"},
	}
)

func StoreAddress(id string) Address {
	if a, ok := stores[id]; ok {
		return a
	}
	return Address{Town: "г. Павлодар", FormattedAddress: "г. Павлодар, склад " + id}
}

func (s *Store) seedLocked() {
	s.st.Seeded = true
	days := s.cfg.HistoryDays
	if days <= 0 || len(s.st.Offers) == 0 {
		return
	}
	perDay := max(s.cfg.OrdersPerDay, 1)
	now := s.now()

	for day := days; day >= 0; day-- {
		count := perDay/2 + s.rnd.IntN(perDay+1)
		if wd := now.AddDate(0, 0, -day).Weekday(); wd == time.Saturday || wd == time.Sunday {
			count += perDay / 2
		}
		for range count {
			local := now.In(s.cfg.Location).AddDate(0, 0, -day)
			at := time.Date(local.Year(), local.Month(), local.Day(), 9+s.rnd.IntN(14), s.rnd.IntN(60), s.rnd.IntN(60), 0, s.cfg.Location)
			if !at.Before(now) {
				continue
			}
			o := s.generate(at, true)
			if o == nil {
				return
			}
			s.age(o, now)
		}
	}
	for range 3 {
		s.generate(now.Add(-time.Duration(s.rnd.IntN(40)+1)*time.Minute), false)
	}
}

func (s *Store) age(o *Order, now time.Time) {
	created := time.UnixMilli(o.CreationDate)
	hours := now.Sub(created).Hours()
	if o.State == StateSignRequired {
		o.State = StateNew
	}
	if hours < 2 {
		return
	}
	step := func(after time.Duration, text string) time.Time {
		at := created.Add(after)
		if at.After(now) {
			at = now
		}
		s.event(o, at, text)
		s.touch(o, at)
		return at
	}

	roll := s.rnd.Float64()
	if roll < 0.06 {
		o.Status, o.State, o.CancellationReason = StatusCancelled, StateArchive, CancelReasons[s.rnd.IntN(len(CancelReasons))]
		step(40*time.Minute, "Продавец отменил заказ: "+o.CancellationReason)
		return
	}
	o.Status, o.State = StatusAccepted, o.acceptedState()
	step(20*time.Minute, "Продавец принял заказ")

	if hours < 30 && roll < 0.5 {
		if o.IsKaspiDelivery && roll < 0.3 {
			o.NumberOfSpace, o.WaybillNumber = 1, "KD"+o.Code
			step(3*time.Hour, "Сформирована накладная "+o.WaybillNumber+", мест: 1")
		}
		return
	}
	if o.IsKaspiDelivery {
		o.NumberOfSpace, o.WaybillNumber = 1+s.rnd.IntN(2), "KD"+o.Code
		step(3*time.Hour, fmt.Sprintf("Сформирована накладная %s, мест: %d", o.WaybillNumber, o.NumberOfSpace))
		o.CourierTransmissionDate = step(20*time.Hour, "Курьер Kaspi Доставки забрал заказ").UnixMilli()
	}
	if roll > 0.985 && o.IsKaspiDelivery {
		o.Status, o.State, o.CancellationReason = StatusCancelled, StateArchive, "BUYER_CANCELLATION"
		step(26*time.Hour, "Покупатель отказался при получении")
		return
	}
	o.Status, o.State = StatusCompleted, StateArchive
	step(28*time.Hour, "Заказ выдан покупателю")
	if roll > 0.97 && o.IsKaspiDelivery {
		o.Status, o.ReturnedToWarehouse = StatusReturned, true
		step(72*time.Hour, "Товар вернулся на склад продавца")
	}
}

func (s *Store) generate(at time.Time, history bool) *Order {
	available := make([]Offer, 0, len(s.st.Offers))
	for _, offer := range s.st.Offers {
		if _, count := offer.AnyStore(); count > 0 {
			available = append(available, offer)
		}
	}
	if len(available) == 0 {
		return nil
	}

	first, last := s.person()
	n := s.nextID()
	o := &Order{
		ID:                 encodeID(n),
		Code:               s.code(),
		CustomerID:         encodeID(int64(fnv32(first + last))),
		Customer:           Person{FirstName: first, LastName: last, CellPhone: fmt.Sprintf("70%d%07d", 5+s.rnd.IntN(3), s.rnd.IntN(10000000))},
		PaymentMode:        PayPrepaid,
		State:              StateNew,
		Status:             StatusApproved,
		CreationDate:       at.UnixMilli(),
		ApprovedByBankDate: at.Add(time.Duration(5+s.rnd.IntN(50)) * time.Second).UnixMilli(),
	}

	roll := s.rnd.Float64()
	switch {
	case roll < 0.5:
		o.DeliveryMode, o.IsKaspiDelivery = ModeLocal, true
	case roll < 0.75:
		o.DeliveryMode = ModePickup
	case roll < 0.85:
		o.DeliveryMode = ModeLocal
	default:
		o.DeliveryMode, o.IsKaspiDelivery = ModeRegionalToDoor, true
	}
	o.Express = o.IsKaspiDelivery && o.DeliveryMode == ModeLocal && s.rnd.Float64() < 0.15
	if s.rnd.Float64() < 0.25 {
		o.PaymentMode, o.CreditTerm = PayCredit, []int{3, 6, 12, 24}[s.rnd.IntN(4)]
		o.SignatureRequired = s.rnd.Float64() < 0.5
		if o.SignatureRequired && !history {
			o.State = StateSignRequired
		}
	}

	lines := 1
	if s.rnd.Float64() < 0.25 {
		lines = 2 + s.rnd.IntN(2)
	}
	picked := map[string]bool{}
	storeID := ""
	for i := 0; i < lines; i++ {
		offer := available[s.rnd.IntN(len(available))]
		if picked[offer.SKU] {
			continue
		}
		store, stock := offer.AnyStore()
		if storeID == "" {
			storeID = store
		}
		qty := 1
		if s.rnd.Float64() < 0.2 {
			qty = min(stock, 2+s.rnd.IntN(3))
		}
		picked[offer.SKU] = true
		product := s.product(offer)
		o.Entries = append(o.Entries, Entry{
			ID:          encodeID(n*10 + int64(i)),
			EntryNumber: len(o.Entries),
			SKU:         offer.SKU,
			Name:        offer.Model,
			Brand:       offer.Brand,
			Category:    product.Category,
			MasterID:    product.MasterID,
			MerchantID:  product.MerchantID,
			Quantity:    qty,
			BasePrice:   offer.Value,
			TotalPrice:  offer.Value * float64(qty),
			StoreID:     store,
			Weight:      float64(500 + s.rnd.IntN(4500)),
		})
		o.TotalPrice += offer.Value * float64(qty)
	}
	o.StoreID = storeID

	days := 1 + s.rnd.IntN(2)
	if o.DeliveryMode == ModeRegionalToDoor {
		days += 2
	}
	o.PlannedDeliveryDate = at.AddDate(0, 0, days).UnixMilli()
	if o.DeliveryMode != ModePickup {
		street := streets[s.rnd.IntN(len(streets))]
		if o.DeliveryMode == ModeLocal {
			street = streets[s.rnd.IntN(5)]
		}
		number := fmt.Sprintf("%d", 1+s.rnd.IntN(180))
		apartment := fmt.Sprintf("%d", 1+s.rnd.IntN(120))
		o.DeliveryAddress = &Address{
			StreetName:       street.street,
			StreetNumber:     number,
			Town:             street.town,
			Apartment:        &apartment,
			FormattedAddress: fmt.Sprintf("%s, %s, %s, кв. %s", street.town, street.street, number, apartment),
		}
	}
	if o.IsKaspiDelivery {
		o.DeliveryCostForSeller = math.Round(math.Min(math.Max(o.TotalPrice*0.01, 599), 1999))
	}
	if o.DeliveryMode == ModeLocal && !o.IsKaspiDelivery {
		o.DeliveryCost = 1000
	}
	s.event(o, at, "Заказ оформлен и одобрен банком")
	s.touch(o, at)

	s.st.Orders = append(s.st.Orders, o)
	s.byID[o.ID] = o
	return o
}

func (s *Store) person() (string, string) {
	last := lastNames[s.rnd.IntN(len(lastNames))]
	if s.rnd.IntN(2) == 0 {
		return maleNames[s.rnd.IntN(len(maleNames))], last
	}
	for _, suffix := range []string{"ов", "ев", "ёв"} {
		if strings.HasSuffix(last, suffix) {
			last += "а"
			break
		}
	}
	return femaleNames[s.rnd.IntN(len(femaleNames))], last
}

func (s *Store) product(offer Offer) Entry {
	if p, ok := s.st.Products[offer.SKU]; ok {
		p.Name, p.Brand = offer.Model, offer.Brand
		s.st.Products[offer.SKU] = p
		return p
	}
	h := int64(fnv32(offer.SKU))
	p := Entry{
		SKU:        offer.SKU,
		Name:       offer.Model,
		Brand:      offer.Brand,
		Category:   categoryOf(offer.Model),
		MasterID:   encodeID(100000000 + h%900000000),
		MerchantID: encodeID(200000000 + h%700000000),
	}
	s.st.Products[offer.SKU] = p
	return p
}

func categoryOf(name string) string {
	for _, rule := range []struct{ word, category string }{
		{"Кабел", "Кабели и провода"}, {"Провод", "Кабели и провода"}, {"Автомат", "Автоматические выключатели"},
		{"Розет", "Розетки и выключатели"}, {"Выключ", "Розетки и выключатели"}, {"Лампа", "Лампы"},
		{"Светил", "Светильники"}, {"Удлин", "Удлинители"}, {"Щит", "Электрощиты"},
	} {
		if strings.Contains(name, rule.word) {
			return rule.category
		}
	}
	return "Электротовары"
}

func fnv32(s string) uint32 {
	h := fnv.New32a()
	_, _ = h.Write([]byte(s))
	return h.Sum32()
}
