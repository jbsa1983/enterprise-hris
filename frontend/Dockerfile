FROM node:20-alpine

WORKDIR /app

# Install dependencies first (leveraging Docker layer caching).
COPY package.json package-lock.json* ./
RUN npm install

COPY . .

EXPOSE 3000

# Development server (hot-reload). For production use `npm run build && start`.
CMD ["npm", "run", "dev"]
