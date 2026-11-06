# Image unique des microservices BAYAM : TypeScript exécuté directement par Node 24 (sans étape de compilation),
# la commande du conteneur choisit le service. Utilisateur sans privilège, dépendances de production seulement.
FROM node:24.22.2-alpine
ENV NODE_ENV=production
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --omit=dev --no-audit --no-fund && npm cache clean --force
COPY src ./src
USER node
EXPOSE 8090 8091
