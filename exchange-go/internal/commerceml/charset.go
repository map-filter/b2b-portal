package commerceml

import (
	"fmt"
	"io"
	"strings"

	"golang.org/x/text/encoding/charmap"
)

func charsetReader(charset string, input io.Reader) (io.Reader, error) {
	switch strings.ToLower(charset) {
	case "windows-1251", "cp1251":
		return charmap.Windows1251.NewDecoder().Reader(input), nil
	case "utf-8", "utf8", "":
		return input, nil
	}
	return nil, fmt.Errorf("неподдерживаемая кодировка %s", charset)
}
