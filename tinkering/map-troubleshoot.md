# Map Page Deployment & Troubleshooting Guide

## Overview

The map page uses Leaflet.js for interactive maps. As of this update, Leaflet is bundled locally via Vite (no external CDN dependency).

---

## Deployment Steps

### Step 1: Commit Changes Locally

```bash
# Check what files changed
git status

# Review the changes
git diff

# Stage all changes
git add .

# Commit with descriptive message
git commit -m "Bundle Leaflet locally via Vite for reliable map loading

- Install leaflet npm package
- Import Leaflet CSS in app.css
- Configure Leaflet in app.js with marker icon fix
- Remove CDN dependency from head.blade.php
- Simplify map component initialization"
```

### Step 2: Push to Remote Repository

```bash
# Push to main branch (adjust branch name if different)
git push origin main
```

### Step 3: Deploy on Production Server

SSH into your production server and run:

```bash
# Navigate to project directory
cd /path/to/lapju

# Pull latest changes
git pull origin main

# Install npm dependencies (important - this installs leaflet)
npm install

# Build assets for production
npm run build

# Clear Laravel caches
php artisan cache:clear
php artisan config:clear
php artisan view:clear
php artisan route:clear

# Optional: Restart queue workers if using them
php artisan queue:restart
```

### Step 4: Verify Deployment

1. Visit `https://lapju.com/map`
2. Open browser DevTools (F12) → Console tab
3. Check for errors
4. Verify map loads and markers appear

---

## Troubleshooting

### Issue: Map Not Displaying

**Symptoms:** Blank area where map should be, no errors in console

**Solutions:**

1. **Check if Leaflet is loaded:**
   ```javascript
   // In browser console
   console.log(typeof L);  // Should print "object"
   console.log(L.version); // Should print "1.9.4"
   ```

2. **Check if CSS is loaded:**
   - Inspect the map container element
   - Verify `.leaflet-container` has proper height
   - Check if Leaflet CSS classes are applied

3. **Force rebuild assets:**
   ```bash
   rm -rf public/build
   npm run build
   ```

### Issue: "L is not defined" Error

**Cause:** Leaflet JS not loaded before map initialization

**Solutions:**

1. **Verify app.js is loaded:**
   ```bash
   # Check manifest.json has app.js entry
   cat public/build/manifest.json | grep app
   ```

2. **Check Vite directive in head.blade.php:**
   ```blade
   @vite(['resources/css/app.css', 'resources/js/app.js'])
   ```

3. **Verify window.L is set:**
   ```javascript
   // In browser console
   console.log(window.L);
   ```

### Issue: Stylesheet Failed to Load

**Symptoms:** Console error about stylesheet URL, map looks broken

**Solutions:**

1. **Verify Leaflet CSS is bundled:**
   ```bash
   # Check if leaflet CSS is in the built file
   grep -l "leaflet" public/build/assets/*.css
   ```

2. **Check app.css import:**
   ```css
   /* resources/css/app.css should have: */
   @import 'leaflet/dist/leaflet.css';
   ```

3. **Rebuild:**
   ```bash
   npm run build
   ```

### Issue: Marker Icons Not Showing

**Symptoms:** Map loads but markers have broken image icons

**Solutions:**

1. **Check marker images exist:**
   ```bash
   ls -la public/build/assets/marker-*.png
   ```

2. **Verify app.js marker configuration:**
   ```javascript
   // resources/js/app.js should have marker icon imports
   import markerIcon from 'leaflet/dist/images/marker-icon.png';
   ```

3. **Clear browser cache:** Hard refresh with `Ctrl+Shift+R` (Windows) or `Cmd+Shift+R` (Mac)

### Issue: Map Shows But No Project Markers

**Symptoms:** Map tiles load, but no colored circle markers

**Solutions:**

1. **Check if projects have coordinates:**
   ```bash
   php artisan tinker
   ```
   ```php
   App\Models\Project::whereHas('location', fn($q) => $q->whereNotNull('latitude'))->count();
   ```

2. **Check mapData in browser:**
   ```javascript
   // In browser console, check Alpine data
   document.querySelector('[x-data]').__x.$data.currentProjects
   ```

3. **Verify location coordinates:**
   ```php
   // In tinker
   App\Models\Location::whereNotNull('latitude')->first();
   ```

### Issue: Preload Warning in Console

**Symptoms:** Warning about preloaded CSS not being used

**Cause:** This is usually harmless - the browser preloaded the file but Vite loaded it differently

**Solution:** Can be ignored if the map works. If persistent:
```bash
# Clear all caches and rebuild
php artisan optimize:clear
npm run build
```

---

## Useful Commands

### Check Current Build Status

```bash
# List built assets
ls -la public/build/assets/

# Check manifest
cat public/build/manifest.json
```

### Check Leaflet Version

```bash
# In package.json
grep leaflet package.json

# Or check node_modules
cat node_modules/leaflet/package.json | grep version
```

### Development Mode

```bash
# Run Vite dev server for hot reloading
npm run dev

# Or use Laravel's combined command
composer run dev
```

### Production Build

```bash
# Always use build for production
npm run build
```

---

## File Reference

| File | Purpose |
|------|---------|
| `resources/js/app.js` | Imports Leaflet, configures marker icons, exposes `window.L` |
| `resources/css/app.css` | Imports Leaflet CSS via `@import 'leaflet/dist/leaflet.css'` |
| `resources/views/partials/head.blade.php` | Loads Vite assets (no CDN links) |
| `resources/views/livewire/map/index.blade.php` | Map component with Alpine.js controller |
| `public/build/manifest.json` | Vite manifest mapping source files to built assets |

---

## Rollback (If Needed)

If the deployment causes issues:

```bash
# On production server
git log --oneline -5  # Find the previous commit hash

# Revert to previous commit
git checkout <previous-commit-hash> -- resources/js/app.js resources/css/app.css resources/views/partials/head.blade.php resources/views/livewire/map/index.blade.php package.json package-lock.json

# Rebuild
npm install
npm run build

# Or fully revert commit
git revert HEAD
npm install
npm run build
```

---

## Quick Health Check Script

Save this as `check-map.sh` and run on server:

```bash
#!/bin/bash
echo "=== Map Page Health Check ==="

echo -e "\n1. Checking Leaflet package..."
grep -q '"leaflet"' package.json && echo "✓ Leaflet in package.json" || echo "✗ Leaflet NOT in package.json"

echo -e "\n2. Checking built assets..."
ls public/build/assets/marker-icon-*.png > /dev/null 2>&1 && echo "✓ Marker icons exist" || echo "✗ Marker icons missing"

echo -e "\n3. Checking Leaflet CSS import..."
grep -q "leaflet/dist/leaflet.css" resources/css/app.css && echo "✓ CSS import exists" || echo "✗ CSS import missing"

echo -e "\n4. Checking app.js Leaflet config..."
grep -q "window.L = L" resources/js/app.js && echo "✓ Leaflet exposed globally" || echo "✗ Leaflet NOT exposed globally"

echo -e "\n5. Checking for CDN references (should be none)..."
grep -r "unpkg.com/leaflet" resources/views/ && echo "✗ CDN references found!" || echo "✓ No CDN references"

echo -e "\n=== Check Complete ==="
```

Run with: `bash check-map.sh`
