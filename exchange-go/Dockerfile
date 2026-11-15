FROM golang:1.23-alpine AS build
WORKDIR /src
COPY go.mod ./
RUN go mod download
COPY . .
RUN CGO_ENABLED=0 go build -o /out/exchange ./cmd/exchange

FROM gcr.io/distroless/static-debian12
COPY --from=build /out/exchange /exchange
EXPOSE 8080
ENTRYPOINT ["/exchange"]
