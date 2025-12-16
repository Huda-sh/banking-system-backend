#!/bin/bash

set -e

ENV=${1:-production}

echo "🚀 Starting deployment for $ENV environment..."

# Check if .env file exists
if [ ! -f .env ]; then
    echo "❌ .env file not found. Please create it first."
    exit 1
fi

# Build images
echo "📦 Building Docker images..."
if [ "$ENV" = "production" ]; then
    docker-compose -f docker-compose.yml -f docker-compose.prod.yml build --no-cache
else
    docker-compose build --no-cache
fi

# Start containers
echo "🚀 Starting containers..."
if [ "$ENV" = "production" ]; then
    docker-compose -f docker-compose.yml -f docker-compose.prod.yml up -d
else
    docker-compose up -d
fi

# Wait for database to be ready
echo "⏳ Waiting for database..."
sleep 15

# Run migrations
echo "🔄 Running migrations..."
if [ "$ENV" = "production" ]; then
    docker-compose -f docker-compose.yml -f docker-compose.prod.yml exec -T app php artisan migrate --force
else
    docker-compose exec -T app php artisan migrate --force
fi

# Clear and cache config (production only)
if [ "$ENV" = "production" ]; then
    echo "⚡ Optimizing application..."
    docker-compose -f docker-compose.yml -f docker-compose.prod.yml exec -T app php artisan config:cache
    docker-compose -f docker-compose.yml -f docker-compose.prod.yml exec -T app php artisan route:cache
    docker-compose -f docker-compose.yml -f docker-compose.prod.yml exec -T app php artisan view:cache
fi

# Restart queue workers
echo "🔄 Restarting queue workers..."
if [ "$ENV" = "production" ]; then
    docker-compose -f docker-compose.yml -f docker-compose.prod.yml restart queue
else
    docker-compose restart queue
fi

echo "✅ Deployment complete!"
echo "📊 Application is running at: http://localhost:8080"