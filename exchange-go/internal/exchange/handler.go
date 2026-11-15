package exchange

import (
	"bytes"
	"context"
	"crypto/rand"
	"crypto/subtle"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"io"
	"log/slog"
	"net/http"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"time"

	"github.com/b2b-portal/exchange/internal/commerceml"
)

const sessionCookie = "b2b_exchange"

type Orders interface {
	Fetch(limit int) ([]commerceml.OrderEvent, error)
	Confirm() (int, error)
}

type Config struct {
	Login     string
	Password  string
	UploadDir string
	FileLimit int64
	BatchSize int
	Location  *time.Location
}

type ImportReport struct {
	ID         string           `json:"id"`
	File       string           `json:"file"`
	StartedAt  time.Time        `json:"started_at"`
	DurationMS int64            `json:"duration_ms"`
	SizeBytes  int64            `json:"size_bytes"`
	Stats      commerceml.Stats `json:"stats"`
	Error      string           `json:"error,omitempty"`
}

type Handler struct {
	cfg       Config
	publisher Publisher
	orders    Orders
	sessions  sync.Map
	mu        sync.Mutex
	reports   []ImportReport
	now       func() time.Time
}

func NewHandler(cfg Config, publisher Publisher, orders Orders) *Handler {
	if cfg.Location == nil {
		cfg.Location = time.UTC
	}
	return &Handler{cfg: cfg, publisher: publisher, orders: orders, now: time.Now}
}

func (h *Handler) Routes() http.Handler {
	mux := http.NewServeMux()
	mux.HandleFunc("/1c_exchange.php", h.exchange)
	mux.HandleFunc("GET /health", func(w http.ResponseWriter, _ *http.Request) {
		writeJSON(w, map[string]string{"status": "ok"})
	})
	mux.HandleFunc("GET /imports", func(w http.ResponseWriter, _ *http.Request) {
		h.mu.Lock()
		defer h.mu.Unlock()
		writeJSON(w, map[string]any{"data": h.reports})
	})
	return mux
}

func (h *Handler) exchange(w http.ResponseWriter, r *http.Request) {
	w.Header().Set("Content-Type", "text/plain; charset=utf-8")
	query := r.URL.Query()
	kind, mode := query.Get("type"), query.Get("mode")

	if mode == "checkauth" {
		h.checkAuth(w, r)
		return
	}

	session, ok := h.session(r)
	if !ok {
		failure(w, "нет авторизации, начните с mode=checkauth")
		return
	}

	switch {
	case mode == "init":
		fmt.Fprintf(w, "zip=no\nfile_limit=%d\n", h.cfg.FileLimit)
	case kind == "catalog" && mode == "file":
		h.upload(w, r, session, query.Get("filename"))
	case kind == "catalog" && mode == "import":
		h.importFile(w, r, session, query.Get("filename"))
	case kind == "sale" && mode == "query":
		h.queryOrders(w)
	case kind == "sale" && mode == "success":
		count, err := h.orders.Confirm()
		if err != nil {
			failure(w, err.Error())
			return
		}
		slog.Info("1С подтвердила получение заказов", "count", count)
		fmt.Fprint(w, "success\n")
	case kind == "sale" && mode == "file":
		_, _ = io.Copy(io.Discard, r.Body)
		fmt.Fprint(w, "success\n")
	default:
		failure(w, fmt.Sprintf("неизвестный режим type=%s mode=%s", kind, mode))
	}
}

func (h *Handler) checkAuth(w http.ResponseWriter, r *http.Request) {
	login, password, ok := r.BasicAuth()
	if !ok || subtle.ConstantTimeCompare([]byte(login), []byte(h.cfg.Login)) != 1 ||
		subtle.ConstantTimeCompare([]byte(password), []byte(h.cfg.Password)) != 1 {
		failure(w, "неверный логин или пароль")
		return
	}

	token := randomID()
	h.sessions.Store(token, h.now())
	fmt.Fprintf(w, "success\n%s\n%s\n", sessionCookie, token)
}

func (h *Handler) session(r *http.Request) (string, bool) {
	cookie, err := r.Cookie(sessionCookie)
	if err != nil {
		return "", false
	}
	started, ok := h.sessions.Load(cookie.Value)
	if !ok || h.now().Sub(started.(time.Time)) > 12*time.Hour {
		return "", false
	}
	return cookie.Value, true
}

func (h *Handler) upload(w http.ResponseWriter, r *http.Request, session, filename string) {
	path, err := h.path(session, filename)
	if err != nil {
		failure(w, err.Error())
		return
	}
	if err := os.MkdirAll(filepath.Dir(path), 0o755); err != nil {
		failure(w, err.Error())
		return
	}

	file, err := os.OpenFile(path, os.O_CREATE|os.O_APPEND|os.O_WRONLY, 0o644)
	if err != nil {
		failure(w, err.Error())
		return
	}
	defer file.Close()

	if _, err := io.Copy(file, http.MaxBytesReader(w, r.Body, h.cfg.FileLimit)); err != nil {
		failure(w, err.Error())
		return
	}
	fmt.Fprint(w, "success\n")
}

func (h *Handler) importFile(w http.ResponseWriter, r *http.Request, session, filename string) {
	path, err := h.path(session, filename)
	if err != nil {
		failure(w, err.Error())
		return
	}

	report := h.run(r.Context(), path, filename)
	if report.Error != "" {
		failure(w, report.Error)
		return
	}
	_ = os.Remove(path)
	fmt.Fprint(w, "success\n")
}

func (h *Handler) run(ctx context.Context, path, filename string) ImportReport {
	report := ImportReport{ID: randomID()[:12], File: filename, StartedAt: h.now()}

	file, err := os.Open(path)
	if err != nil {
		report.Error = "файл не загружен: " + filename
		return h.remember(report)
	}
	defer file.Close()

	if info, err := file.Stat(); err == nil {
		report.SizeBytes = info.Size()
	}

	parser := commerceml.NewParser(&publishingSink{ctx: ctx, publisher: h.publisher, importID: report.ID}, h.cfg.BatchSize)
	if err := parser.Parse(file); err != nil {
		report.Error = err.Error()
	}
	report.Stats = parser.Stats()
	report.DurationMS = h.now().Sub(report.StartedAt).Milliseconds()

	if report.Error == "" {
		if err := h.publisher.Publish(ctx, "catalog.import_finished", report); err != nil {
			report.Error = err.Error()
		}
	}

	slog.Info("импорт из 1С", "file", filename, "ms", report.DurationMS, "products", report.Stats.Products, "prices", report.Stats.Prices, "error", report.Error)
	return h.remember(report)
}

func (h *Handler) remember(report ImportReport) ImportReport {
	h.mu.Lock()
	defer h.mu.Unlock()
	h.reports = append([]ImportReport{report}, h.reports...)
	if len(h.reports) > 20 {
		h.reports = h.reports[:20]
	}
	return report
}

func (h *Handler) queryOrders(w http.ResponseWriter) {
	orders, err := h.orders.Fetch(100)
	if err != nil {
		failure(w, err.Error())
		return
	}

	var buffer bytes.Buffer
	if err := commerceml.WriteOrders(&buffer, orders, h.now(), h.cfg.Location); err != nil {
		failure(w, err.Error())
		return
	}
	slog.Info("отдаю заказы в 1С", "count", len(orders))
	w.Header().Set("Content-Type", "application/xml; charset=utf-8")
	_, _ = w.Write(buffer.Bytes())
}

func (h *Handler) path(session, filename string) (string, error) {
	name := filepath.Base(filepath.Clean("/" + filename))
	if name == "/" || name == "." || !strings.HasSuffix(strings.ToLower(name), ".xml") {
		return "", fmt.Errorf("недопустимое имя файла %q", filename)
	}
	return filepath.Join(h.cfg.UploadDir, session, name), nil
}

func failure(w http.ResponseWriter, message string) {
	slog.Warn("обмен с 1С: ошибка", "message", message)
	fmt.Fprintf(w, "failure\n%s\n", message)
}

func writeJSON(w http.ResponseWriter, payload any) {
	w.Header().Set("Content-Type", "application/json")
	_ = json.NewEncoder(w).Encode(payload)
}

func randomID() string {
	buffer := make([]byte, 16)
	_, _ = rand.Read(buffer)
	return hex.EncodeToString(buffer)
}
