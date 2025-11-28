# Photo Upload Fix - Setup Guide

This guide explains how to apply the large photo upload fix to prevent lingering processes and server overload.

## 🎯 Problem Summary

**Before Fix:**
- Mobile users upload 4-8MB photos
- nginx rejects uploads (413 Payload Too Large)
- Browser retries infinitely
- Processes linger and accumulate
- CPU and RAM max out

**After Fix:**
- Photos compressed to ~2MB client-side
- Server configured to accept up to 10MB
- Proper error handling prevents retry loops
- No lingering processes
- Normal CPU/RAM usage

---

## ✅ What Was Changed

### 1. **Client-Side (Automatic)**
- ✅ Added browser-image-compression library
- ✅ Photos automatically compressed before upload
- ✅ Max size: 2MB after compression
- ✅ Progress indicators show compression status
- ✅ Error handling for 413/500/timeout errors

### 2. **Application (Automatic)**
- ✅ Updated validation: max:2048 (2MB)
- ✅ Configured Livewire upload limits
- ✅ Added rate limiting (10 uploads/minute)

### 3. **Server (Requires Setup)**
- ⚠️ **REQUIRES ACTION:** Apply nginx/PHP configuration below

---

## 🚀 Setup Instructions

### For Laravel Valet (Development - macOS)

#### Option 1: Site-Specific Configuration (Recommended)

```bash
# 1. Create site-specific nginx config directory
mkdir -p ~/.config/valet/Nginx

# 2. Copy the configuration
cp valet-nginx.conf ~/.config/valet/Nginx/lapju.test

# 3. Restart Valet
valet restart

# 4. Test the configuration
curl -I http://lapju.test
```

#### Option 2: Global Valet Configuration

```bash
# 1. Edit global Valet nginx config
code ~/.config/valet/nginx.conf

# 2. Add inside the server block:
client_max_body_size 10M;
client_body_buffer_size 256k;
fastcgi_read_timeout 300s;

# 3. Restart Valet
valet restart
```

### For Production (Linux with nginx + php-fpm)

#### Step 1: Update nginx Configuration

```bash
# 1. Edit your site's nginx config
sudo nano /etc/nginx/sites-available/lapju.com

# 2. Add inside the server block:
client_max_body_size 10M;
client_body_buffer_size 256k;
client_body_timeout 60s;
fastcgi_read_timeout 300s;
fastcgi_buffers 16 16k;
fastcgi_buffer_size 32k;

# 3. Test nginx configuration
sudo nginx -t

# 4. Reload nginx
sudo systemctl reload nginx
```

#### Step 2: Update PHP Configuration

**Option A: Via php.ini (System-wide)**

```bash
# 1. Find php.ini location
php --ini

# 2. Edit php.ini
sudo nano /etc/php/8.2/fpm/php.ini

# 3. Update these values:
upload_max_filesize = 10M
post_max_size = 10M
memory_limit = 256M
max_execution_time = 300
max_input_time = 300

# 4. Restart PHP-FPM
sudo systemctl restart php8.2-fpm
```

**Option B: Via php-fpm pool config (Site-specific)**

```bash
# 1. Edit php-fpm pool config
sudo nano /etc/php/8.2/fpm/pool.d/www.conf

# 2. Add these lines:
php_admin_value[upload_max_filesize] = 10M
php_admin_value[post_max_size] = 10M
php_admin_value[memory_limit] = 256M
php_admin_value[max_execution_time] = 300

# 3. Restart PHP-FPM
sudo systemctl restart php8.2-fpm
```

**Option C: Via .user.ini (Already Created)**

The file `public/.user.ini` has been created automatically.
PHP-FPM will load it automatically if `user_ini.filename = ".user.ini"` is set in php.ini.

---

## 🧪 Verification Steps

### 1. Test Upload Limits

```bash
# Check current PHP settings
php -i | grep -E "upload_max_filesize|post_max_size|memory_limit"

# Should show:
# upload_max_filesize => 10M
# post_max_size => 10M
# memory_limit => 256M
```

### 2. Test nginx Configuration

```bash
# For Valet
curl -I http://lapju.test

# For Production
curl -I https://lapju.com

# Look for custom headers or no 413 errors
```

### 3. Test File Upload

1. Open Progress page on mobile device
2. Select a large photo (4-8MB)
3. Watch for compression message: "🔄 Compressing X.XXM photo..."
4. Should show: "✅ Ready to upload (X.XXM, saved XX%)"
5. Click "Upload Photo"
6. Should upload successfully without 413 error

### 4. Monitor Processes

```bash
# Before test - count PHP processes
ps aux | grep php-fpm | wc -l

# Upload 5 photos

# After test - count PHP processes again
ps aux | grep php-fpm | wc -l

# Should NOT increase significantly
# Old behavior: Would jump to 50+ processes
# New behavior: Should stay under 20 processes
```

---

## 🔍 Troubleshooting

### Issue: Still Getting 413 Errors

**Solution 1: Check nginx is reading the config**
```bash
# Valet
cat ~/.config/valet/Nginx/lapju.test

# Production
sudo nginx -T | grep client_max_body_size
```

**Solution 2: Increase client_max_body_size**
```bash
# If 10M is still not enough, increase to 20M
client_max_body_size 20M;

# Then restart nginx
valet restart  # or sudo systemctl reload nginx
```

### Issue: Photos Not Compressing

**Check browser console:**
```javascript
// Open DevTools > Console
// Should see:
Original photo size: X.XXM
Compressed to: X.XXM (XX% smaller)
```

**If compression fails:**
- Check internet connection (CDN must load)
- Check browser compatibility (modern browsers only)
- Check file type (must be image/*)

### Issue: Uploads Timing Out

**Increase timeouts:**

nginx:
```nginx
client_body_timeout 120s;  # Increase from 60s
fastcgi_read_timeout 600s; # Increase from 300s
```

PHP:
```ini
max_execution_time = 600  # Increase from 300
```

### Issue: Still Seeing Lingering Processes

**Check for:**
1. Old cached JavaScript (clear browser cache)
2. Multiple browser tabs open (close extras)
3. Background retries (check Network tab in DevTools)

**Fix:**
```bash
# Kill all stuck php-fpm processes
sudo pkill -f "php-fpm"

# Restart PHP-FPM
sudo systemctl restart php8.2-fpm  # or valet restart
```

---

## 📊 Monitoring

### Check Upload Performance

```bash
# Run the performance monitor
bash tinkering/monitor-performance.sh

# Look for:
# - PHP processes should stay under 20
# - Memory usage should be stable
# - No errors in logs
```

### Check Laravel Logs

```bash
# Watch for upload errors
tail -f storage/logs/laravel.log | grep -i "upload\|413\|payload"
```

### Check Nginx Error Logs

```bash
# Valet
tail -f ~/.config/valet/Log/nginx-error.log

# Production
sudo tail -f /var/log/nginx/error.log
```

---

## 🎯 Success Criteria

After applying the fix, you should see:

✅ **Client-Side:**
- Photos compressed automatically
- "Compressing..." message appears
- Upload size reduced by 50-80%
- No 413 errors in console

✅ **Server-Side:**
- No "413 Payload Too Large" in logs
- No "client intended to send too large body" errors
- PHP processes stable (under 20)
- CPU/RAM usage normal

✅ **User Experience:**
- Fast uploads (compressed files)
- Clear progress indicators
- Helpful error messages
- No hangs or freezes

---

## 📝 Configuration Files Reference

| File | Purpose | Auto-Applied |
|------|---------|--------------|
| `resources/views/livewire/progress/index.blade.php` | Client-side compression | ✅ Yes |
| `config/livewire.php` | Livewire upload limits | ✅ Yes |
| `public/.user.ini` | PHP settings (php-fpm) | ✅ Yes* |
| `public/.htaccess` | PHP settings (Apache mod_php) | ✅ Yes* |
| `valet-nginx.conf` | nginx configuration template | ⚠️ Manual |

*Automatically loaded if server supports it

---

## 🚨 Important Notes

1. **Client-side compression is the PRIMARY fix**
   - Even without server config changes, 2MB uploads will work
   - Server config is for safety margin and better error handling

2. **Don't skip the server configuration**
   - Without it, you'll still see 413 errors occasionally
   - nginx limits are usually 1MB by default

3. **Test on actual mobile devices**
   - Desktop browsers may behave differently
   - Test on iOS Safari and Android Chrome

4. **Monitor after deployment**
   - Watch for 413 errors in logs
   - Check PHP process count
   - Monitor memory usage

---

## 🔄 Rollback (If Needed)

If you need to revert the changes:

```bash
# 1. Remove nginx config
rm ~/.config/valet/Nginx/lapju.test
valet restart

# 2. Restore validation
# Edit progress/index.blade.php
# Change max:2048 back to max:5120
# Change "2MB" back to "5MB"

# 3. Reset Livewire config
# Edit config/livewire.php
# Set 'rules' => null
# Set 'middleware' => null

# 4. Clear caches
php artisan config:clear
php artisan cache:clear
```

---

## 📚 Additional Resources

- Browser Image Compression Library: https://github.com/Donaldcwl/browser-image-compression
- nginx File Upload Configuration: https://nginx.org/en/docs/http/ngx_http_core_module.html#client_max_body_size
- PHP File Upload Limits: https://www.php.net/manual/en/ini.core.php#ini.upload-max-filesize
- Laravel File Uploads: https://laravel.com/docs/filesystem#file-uploads
- Livewire File Uploads: https://livewire.laravel.com/docs/uploads

---

**Last Updated:** 2025-11-28
**LAPJU Version:** 1.0
**Fix Version:** 1.0
