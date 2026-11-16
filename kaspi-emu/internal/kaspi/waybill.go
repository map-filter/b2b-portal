package kaspi

import (
	"bytes"
	"fmt"
	"strings"
	"time"
)

func WaybillPDF(o Order) []byte {
	lines := []string{
		"KASPI DELIVERY - WAYBILL (EMULATOR)",
		"",
		"Waybill: " + o.WaybillNumber,
		"Order: " + o.Code,
		fmt.Sprintf("Places: %d", o.NumberOfSpace),
		fmt.Sprintf("Items: %d", len(o.Entries)),
		fmt.Sprintf("Total: %.0f KZT", o.TotalPrice),
	}
	if o.CourierTransmissionPlanningDate > 0 {
		lines = append(lines, "Courier pickup: "+time.UnixMilli(o.CourierTransmissionPlanningDate).UTC().Add(5*time.Hour).Format("02.01.2006 15:04"))
	}
	for i := 1; i <= max(o.NumberOfSpace, 1); i++ {
		lines = append(lines, "", fmt.Sprintf("[ %s-%d / %d ]", o.WaybillNumber, i, max(o.NumberOfSpace, 1)))
	}

	var content strings.Builder
	content.WriteString("BT /F1 14 Tf 50 780 Td 18 TL\n")
	for _, line := range lines {
		content.WriteString("(" + escapePDF(line) + ") Tj T*\n")
	}
	content.WriteString("ET")

	objects := []string{
		"<< /Type /Catalog /Pages 2 0 R >>",
		"<< /Type /Pages /Kids [3 0 R] /Count 1 >>",
		"<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>",
		"<< /Type /Font /Subtype /Type1 /BaseFont /Courier >>",
		fmt.Sprintf("<< /Length %d >>\nstream\n%s\nendstream", content.Len(), content.String()),
	}

	var buf bytes.Buffer
	buf.WriteString("%PDF-1.4\n")
	offsets := make([]int, len(objects))
	for i, obj := range objects {
		offsets[i] = buf.Len()
		fmt.Fprintf(&buf, "%d 0 obj\n%s\nendobj\n", i+1, obj)
	}
	xref := buf.Len()
	fmt.Fprintf(&buf, "xref\n0 %d\n0000000000 65535 f \n", len(objects)+1)
	for _, off := range offsets {
		fmt.Fprintf(&buf, "%010d 00000 n \n", off)
	}
	fmt.Fprintf(&buf, "trailer\n<< /Size %d /Root 1 0 R >>\nstartxref\n%d\n%%%%EOF\n", len(objects)+1, xref)
	return buf.Bytes()
}

func escapePDF(s string) string {
	return strings.NewReplacer(`\`, `\\`, "(", `\(`, ")", `\)`).Replace(s)
}
