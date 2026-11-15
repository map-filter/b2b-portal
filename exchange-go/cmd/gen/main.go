package main

import (
	"bufio"
	"encoding/xml"
	"flag"
	"fmt"
	"log"
	"math/rand/v2"
	"os"
	"path/filepath"
	"strings"
)

type group struct {
	id, name string
	children []group
}

var catalog = []group{
	{"1c-g-cable", "Кабель и провод (1С)", []group{{"1c-g-power", "Кабель силовой (1С)", nil}, {"1c-g-wire", "Провод монтажный (1С)", nil}}},
	{"1c-g-modular", "Модульное оборудование (1С)", []group{{"1c-g-breaker", "Автоматические выключатели (1С)", nil}}},
	{"1c-g-light", "Светотехника (1С)", []group{{"1c-g-lamp", "Светодиодные лампы (1С)", nil}}},
}

var leaves = []struct{ id, prefix, unit string }{
	{"1c-g-power", "Кабель ВВГнг(А)-LS", "м"},
	{"1c-g-wire", "Провод ПВС", "м"},
	{"1c-g-breaker", "Автоматический выключатель", "шт"},
	{"1c-g-lamp", "Лампа светодиодная", "шт"},
}

func main() {
	count := flag.Int("products", 1000, "сколько товаров сгенерировать")
	out := flag.String("out", ".", "куда положить import.xml и offers.xml")
	flag.Parse()

	random := rand.New(rand.NewPCG(1, 2))
	must(write(filepath.Join(*out, "import.xml"), func(w *bufio.Writer) { writeImport(w, *count) }))
	must(write(filepath.Join(*out, "offers.xml"), func(w *bufio.Writer) { writeOffers(w, *count, random) }))
	fmt.Printf("готово: %d товаров в %s\n", *count, *out)
}

func writeImport(w *bufio.Writer, count int) {
	w.WriteString(xml.Header)
	w.WriteString(`<КоммерческаяИнформация ВерсияСхемы="2.08" ДатаФормирования="2026-11-15T10:00:00">` + "\n<Классификатор><Ид>1c-classifier</Ид><Наименование>Основной</Наименование><Группы>\n")
	var writeGroups func([]group)
	writeGroups = func(groups []group) {
		for _, g := range groups {
			fmt.Fprintf(w, "<Группа><Ид>%s</Ид><Наименование>%s</Наименование>", g.id, escape(g.name))
			if len(g.children) > 0 {
				w.WriteString("<Группы>")
				writeGroups(g.children)
				w.WriteString("</Группы>")
			}
			w.WriteString("</Группа>\n")
		}
	}
	writeGroups(catalog)
	w.WriteString("</Группы><Свойства><Свойство><Ид>1c-p-variant</Ид><Наименование>Исполнение</Наименование></Свойство></Свойства></Классификатор>\n")
	w.WriteString("<Каталог СодержитТолькоИзменения=\"false\"><Ид>1c-catalog</Ид><ИдКлассификатора>1c-classifier</ИдКлассификатора><Наименование>Основной каталог</Наименование><Товары>\n")
	for i := 1; i <= count; i++ {
		leaf := leaves[i%len(leaves)]
		fmt.Fprintf(w, "<Товар><Ид>1c-%06d</Ид><Артикул>1C-%06d</Артикул><Наименование>%s, исполнение %d (демо 1С)</Наименование><БазоваяЕдиница>%s</БазоваяЕдиница><Группы><Ид>%s</Ид></Группы><Изготовитель><Наименование>Импорт1С</Наименование></Изготовитель><ЗначенияСвойств><ЗначенияСвойства><Ид>1c-p-variant</Ид><Значение>%d</Значение></ЗначенияСвойства></ЗначенияСвойств></Товар>\n",
			i, i, escape(leaf.prefix), i, leaf.unit, leaf.id, i%10+1)
	}
	w.WriteString("</Товары></Каталог></КоммерческаяИнформация>\n")
}

func writeOffers(w *bufio.Writer, count int, random *rand.Rand) {
	w.WriteString(xml.Header)
	w.WriteString(`<КоммерческаяИнформация ВерсияСхемы="2.08"><ПакетПредложений СодержитТолькоИзменения="false"><Ид>1c-offers</Ид>` + "\n")
	w.WriteString("<ТипыЦен><ТипЦены><Ид>1c-pt-retail</Ид><Наименование>Розничная</Наименование><Валюта>KZT</Валюта></ТипЦены><ТипЦены><Ид>1c-pt-opt</Ид><Наименование>Оптовая</Наименование><Валюта>KZT</Валюта></ТипЦены></ТипыЦен>\n")
	w.WriteString("<Склады><Склад><Ид>1c-w-main</Ид><Наименование>Павлодар, основной склад</Наименование></Склад></Склады><Предложения>\n")
	for i := 1; i <= count; i++ {
		price := 200 + random.IntN(20000)
		fmt.Fprintf(w, "<Предложение><Ид>1c-%06d</Ид><Цены><Цена><ИдТипаЦены>1c-pt-retail</ИдТипаЦены><ЦенаЗаЕдиницу>%d.00</ЦенаЗаЕдиницу></Цена><Цена><ИдТипаЦены>1c-pt-opt</ИдТипаЦены><ЦенаЗаЕдиницу>%d.00</ЦенаЗаЕдиницу></Цена></Цены><Остатки><Остаток><Склад><Ид>1c-w-main</Ид><Количество>%d</Количество></Склад></Остаток></Остатки></Предложение>\n",
			i, price, price*88/100, random.IntN(500))
	}
	w.WriteString("</Предложения></ПакетПредложений></КоммерческаяИнформация>\n")
}

func write(path string, fill func(*bufio.Writer)) error {
	file, err := os.Create(path)
	if err != nil {
		return err
	}
	defer file.Close()
	w := bufio.NewWriterSize(file, 1<<20)
	fill(w)
	return w.Flush()
}

func escape(value string) string {
	var buffer strings.Builder
	_ = xml.EscapeText(&buffer, []byte(value))
	return buffer.String()
}

func must(err error) {
	if err != nil {
		log.Fatal(err)
	}
}
