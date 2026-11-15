FROM node:22-alpine AS client
ARG VITE_APP_BASE=/
WORKDIR /app
COPY client-react/package.json client-react/package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY client-react/ .
RUN VITE_APP_BASE=$VITE_APP_BASE npm run build

FROM node:22-alpine AS manager
ARG VITE_APP_BASE=/
WORKDIR /app
COPY manager-vue/package.json manager-vue/package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY manager-vue/ .
RUN VITE_APP_BASE=$VITE_APP_BASE npm run build

FROM nginx:alpine
COPY infra/web/default.conf /etc/nginx/conf.d/default.conf
COPY --from=client /app/dist /srv/client
COPY --from=manager /app/dist /srv/manager
