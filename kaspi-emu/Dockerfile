FROM golang:1.23-alpine AS build
WORKDIR /src
COPY go.mod ./
COPY . .
RUN CGO_ENABLED=0 go build -o /out/kaspi-emu ./cmd/kaspi-emu

FROM gcr.io/distroless/static-debian12
COPY --from=build /out/kaspi-emu /kaspi-emu
EXPOSE 8080
ENTRYPOINT ["/kaspi-emu"]
