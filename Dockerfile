FROM node:20-alpine
WORKDIR /app
COPY package.json package-lock.json ./
# canvas/path2d are optional pdfjs extras we don't need on the server
RUN npm ci --omit=dev --omit=optional && npm cache clean --force
COPY . .
RUN mkdir -p out uploads
ENV NODE_ENV=production PORT=4817
EXPOSE 4817
CMD ["node", "server.mjs"]
