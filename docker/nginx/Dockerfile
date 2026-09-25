# syntax=docker/dockerfile:1.7
# Web image: nginx + built SPAs (storefront at /, admin at /admin).
# In docker-compose (dev) the dist folders are bind-mounted instead.

FROM node:25-alpine AS storefront
WORKDIR /build
COPY storefront/package.json storefront/package-lock.json ./
RUN npm ci
COPY storefront/ ./
RUN npm run build

FROM node:25-alpine AS admin
WORKDIR /build
COPY admin/package.json admin/package-lock.json ./
RUN npm ci
COPY admin/ ./
RUN npm run build

FROM nginx:1.27-alpine AS production
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY docker/nginx/snippets/ /etc/nginx/snippets/
COPY --from=storefront /build/dist /var/www/storefront
COPY --from=admin /build/dist /var/www/admin
COPY backend/public /var/www/backend/public
