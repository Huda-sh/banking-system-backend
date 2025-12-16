#!/bin/bash

set -e

echo "🚀 Starting deployment..."

# Check if .env file exists
if [ ! -f .env ]; then
    echo "❌ .env file not found. Please create it first."
    exit 1
fi

# Build images
echo "📦 Building Docker images..."
docker compose build --no-cache

# Start containers
echo "🚀 Starting containers..."
docker compose up -d

# Wait for application to be ready
echo "⏳ Waiting for application to start..."
sleep 5

# Run migrations
echo "🔄 Running migrations..."
docker compose exec -T app php artisan migrate --force

# Optimize application
echo "⚡ Optimizing application..."
docker compose exec -T app php artisan optimize || true

echo "✅ Deployment complete!"
echo "📊 Application is running at: http://localhost:8080"