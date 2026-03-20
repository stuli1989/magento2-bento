# Installation Guide - Bento Integration with Async Events

**Document Version:** 1.2
**Date:** March 20, 2026
**Estimated Time:** 2-3 hours

---

## Table of Contents

1. [Prerequisites](#prerequisites)
2. [Infrastructure Setup](#infrastructure-setup)
3. [Module Installation](#module-installation)
4. [Configuration](#configuration)
5. [Queue Consumer Setup](#queue-consumer-setup)
6. [Verification](#verification)
7. [Troubleshooting](#troubleshooting)

---

## Prerequisites

### Server Requirements

| Requirement | Minimum | Recommended |
|-------------|---------|-------------|
| **Magento** | 2.4.4 | 2.4.6+ |
| **PHP** | 8.1 | 8.2 |
| **MySQL** | 8.0 | 8.0 |
| **Elasticsearch** | 7.17 | 8.x |
| **RabbitMQ** | 3.8 | 3.11+ |
| **Composer** | 2.x | 2.x |

### Bento Account Requirements

Before starting, ensure you have:

1. **Bento Account** - Sign up at https://app.bentonow.com
2. **Site UUID** - Found in Settings > Site Settings
3. **Publishable Key** - Found in Settings > API Keys
4. **Secret Key** - Found in Settings > API Keys

### Server Access

- SSH access to Magento server
- Composer access (with auth.json configured)
- Ability to run `bin/magento` commands
- Supervisor or systemd access for queue consumers

---

## Infrastructure Setup

### Step 1: Install RabbitMQ (If Not Already Installed)

**Ubuntu/Debian:**
```bash
# Add RabbitMQ repository
curl -fsSL https://github.com/rabbitmq/signing-keys/releases/download/2.0/rabbitmq-release-signing-key.asc | sudo apt-key add -
sudo apt-get update
sudo apt-get install -y rabbitmq-server

# Enable and start
sudo systemctl enable rabbitmq-server
sudo systemctl start rabbitmq-server

# Enable management plugin (optional, for web UI)
sudo rabbitmq-plugins enable rabbitmq_management

# Create Magento user
sudo rabbitmqctl add_user magento 'your_secure_password'
sudo rabbitmqctl set_permissions -p / magento ".*" ".*" ".*"
sudo rabbitmqctl set_user_tags magento administrator
```

**Docker (Alternative):**
```bash
docker run -d --name rabbitmq \
  -p 5672:5672 \
  -p 15672:15672 \
  -e RABBITMQ_DEFAULT_USER=magento \
  -e RABBITMQ_DEFAULT_PASS=your_secure_password \
  rabbitmq:3-management
```

### Step 2: Configure Magento for RabbitMQ

Edit `app/etc/env.php`:

```php
<?php
return [
    // ... other configuration ...

    'queue' => [
        'amqp' => [
            'host' => 'localhost',
            'port' => '5672',
            'user' => 'magento',
            'password' => 'YOUR_RABBITMQ_PASSWORD',
            'virtualhost' => '/magento',
            'ssl' => ''
        ],
    ],

    // ... other configuration ...
];
```

> **Note:** For RabbitMQ installation and setup, see `SERVER_TEAM_INSTRUCTIONS.md` (forward to your hosting/server team).

Verify connection:
```bash
bin/magento queue:consumers:list
```

### Step 3: Verify Elasticsearch (For Event Log Search)

Elasticsearch should already be configured for Magento catalog search. Verify:

```bash
curl -X GET "localhost:9200/_cluster/health?pretty"
```

Expected response includes `"status": "green"` or `"status": "yellow"`.

---

## Module Installation

### Step 1: Install Aligent Async Events

```bash
cd /path/to/magento

# Install via Composer
composer require aligent/async-events:^3.0

# Enable module
bin/magento module:enable Aligent_AsyncEvents

# Run setup
bin/magento setup:upgrade
bin/magento setup:di:compile    # See note below
bin/magento cache:clean
```

> **Note on `setup:di:compile`:** Required on staging/production environments. Not needed in developer mode (Magento auto-generates interceptors on the fly). If running on Windows in developer mode, skip this step entirely -- it will fail due to pipe characters in generated filenames.

### Step 2: Install ArtLounge Modules

**Option A: Manual Installation (Recommended for Custom Modules)**

```bash
# Create module directories
mkdir -p app/code/ArtLounge/BentoCore
mkdir -p app/code/ArtLounge/BentoEvents
mkdir -p app/code/ArtLounge/BentoTracking

# Copy module files (from this package)
cp -r modules/ArtLounge/BentoCore/* app/code/ArtLounge/BentoCore/
cp -r modules/ArtLounge/BentoEvents/* app/code/ArtLounge/BentoEvents/
cp -r modules/ArtLounge/BentoTracking/* app/code/ArtLounge/BentoTracking/
```

**Option B: Composer (If Packaged)**

```bash
# If modules are in a private repository
composer config repositories.artlounge vcs git@github.com:artlounge/magento-bento-modules.git
composer require artlounge/module-bento-core artlounge/module-bento-events artlounge/module-bento-tracking
```

### Step 3: Enable and Setup Modules

```bash
# Enable all modules
bin/magento module:enable ArtLounge_BentoCore ArtLounge_BentoEvents ArtLounge_BentoTracking

# Run setup (creates async_event_subscriber table + registers 9 event subscriptions)
bin/magento setup:upgrade

# Compile DI (staging/production only — not needed in developer mode)
bin/magento setup:di:compile

# Deploy static content (if in production mode)
bin/magento setup:static-content:deploy -f

# Clean and flush cache
bin/magento cache:clean
bin/magento cache:flush

# Verify installation
bin/magento module:status | grep -E "(Aligent|ArtLounge)"
```

Expected output:
```
Aligent_AsyncEvents
ArtLounge_BentoCore
ArtLounge_BentoEvents
ArtLounge_BentoTracking
```

**Important:** `setup:upgrade` must complete fully before running `setup:di:compile`. The upgrade creates the `async_event_subscriber` database table and runs the `CreateBentoSubscriptions` data patch which registers all 9 Bento event subscriptions. If upgrade fails partway through, you may need to run `bin/magento setup:db-data:upgrade` separately to execute the data patches.

---

## Configuration

### Step 1: Admin Panel Configuration

Navigate to: **Stores > Configuration > ArtLounge > Bento Integration**

#### General Settings

| Setting | Value | Description |
|---------|-------|-------------|
| **Enable Integration** | Yes | Master switch for all Bento integration |
| **Site UUID** | (from Bento) | Your Bento site identifier |
| **Publishable Key** | (from Bento) | API key for client-side tracking |
| **Secret Key** | (from Bento) | API key for server-side events (encrypted) |
| **Webhook Base URL** | `https://track.bentonow.com/webhooks` | Bento webhook endpoint |
| **Debug Mode** | No | Enable for troubleshooting (logs all payloads) |

#### Order Events

| Setting | Value | Description |
|---------|-------|-------------|
| **Enable Order Events** | Yes | Track order lifecycle |
| **Track Order Placed** | Yes | Send $purchase events |
| **Track Order Shipped** | Yes | Send $OrderShipped events |
| **Track Order Cancelled** | Yes | Send $OrderCanceled events |
| **Track Order Refunded** | Yes | Send $OrderRefunded events |
| **Include Tax in Totals** | Yes | Include tax in total_value |
| **Currency Multiplier** | 100 | Convert to cents (100) or keep whole (1) |

#### Customer Events

| Setting | Value | Description |
|---------|-------|-------------|
| **Enable Customer Events** | Yes | Track customer lifecycle |
| **Track Customer Registration** | Yes | Send $Subscriber events |
| **Track Customer Updates** | No | Send $CustomerUpdated events (optional) |
| **Default Tags** | `lead,mql` | Tags applied to new subscribers |

#### Newsletter Events

| Setting | Value | Description |
|---------|-------|-------------|
| **Enable Newsletter Events** | Yes | Track newsletter subscriptions |
| **Track Subscribe** | Yes | Send $subscribe events |
| **Track Unsubscribe** | Yes | Send $unsubscribe events |

#### Abandoned Cart

| Setting | Value | Description |
|---------|-------|-------------|
| **Enable Abandoned Cart** | Yes | Track cart abandonment |
| **Delay (Minutes)** | 60 | Wait time before considering cart abandoned |
| **Minimum Cart Value** | 500 | Only trigger for carts above this value (INR) |
| **Require Email** | Yes | Only trigger if customer email is known |
| **Exclude Customer Groups** | Wholesale | Don't send for these groups (multi-select) |
| **Processing Method** | Queue | Choose Queue (recommended) or Cron |

#### Client-Side Tracking

| Setting | Value | Description |
|---------|-------|-------------|
| **Enable Tracking** | Yes | Master switch for client-side events |
| **Track Product Views** | Yes | Send $view events on product pages |
| **Track Add to Cart** | Yes | Send add_to_cart events |
| **Track Checkout Started** | Yes | Send $checkoutStarted events |
| **Add to Cart Selector** | `.add_cart_btn.addToCart, #product-addtocart-button` | CSS selector for add to cart button(s) |

**Note on Add to Cart Selector:** The default selector targets both the theme's custom ATC button (`.add_cart_btn.addToCart`) and Magento's standard button (`#product-addtocart-button`). The variant table (color table) per-row ATC buttons are tracked automatically via form-level detection and do not need a separate selector.

#### Advanced Settings

| Setting | Value | Description |
|---------|-------|-------------|
| **Max Retry Attempts** | 5 | Retry limit before dead-lettering |
| **Log Retention (Days)** | 30 | How long to keep event logs |
| **Enable Elasticsearch Indexing** | Yes | Enable advanced log search |

### Step 2: Test Connection

After saving configuration:

1. Click **"Test Connection"** button
2. Verify success message: "Successfully connected to Bento API"
3. Check response time (should be < 500ms)

If connection fails:
- Verify API credentials
- Check firewall rules (outbound HTTPS to bentonow.com)
- Review `var/log/bento.log` for details

### Step 3: Verify Event Subscriptions

The module automatically creates subscriptions in the `async_event_subscriber` table via a data patch. Verify:

```bash
bin/magento bento:status
```

Or check the database directly:
```sql
SELECT event_name, status, metadata FROM async_event_subscriber WHERE metadata = 'bento';
```

You should see 9 subscriptions:
- `bento.order.placed`
- `bento.order.shipped`
- `bento.order.cancelled`
- `bento.order.refunded`
- `bento.customer.created`
- `bento.customer.updated`
- `bento.newsletter.subscribed`
- `bento.newsletter.unsubscribed`
- `bento.cart.abandoned`

---

## Queue Consumer Setup

### Quick Start

```bash
# Required for server-side events (orders, customers, newsletter, abandoned carts)
php bin/magento queue:consumers:start event.trigger.consumer &
php bin/magento queue:consumers:start event.retry.consumer &
```

> **Note:** These must run persistently on staging/production. See `SERVER_TEAM_INSTRUCTIONS.md` section 5 for systemd setup. The commands above are suitable for development/testing only.

### Understanding Queue Consumers

Queue consumers are background processes that:
1. Listen for messages in the queue
2. Process events asynchronously
3. Handle retries on failure
4. Log results for tracking

**Required Consumers:**
| Consumer | Purpose |
|----------|---------|
| `event.trigger.consumer` | Processes new events |
| `event.retry.consumer` | Handles failed event retries |

### Option A: Supervisor (Recommended for Production)

**Install Supervisor:**
```bash
sudo apt-get install supervisor
```

**Create Configuration:**
```bash
sudo nano /etc/supervisor/conf.d/magento-queues.conf
```

**Configuration Content:**
```ini
[program:magento-event-trigger]
command=/usr/bin/php /var/www/magento/bin/magento queue:consumers:start event.trigger.consumer --max-messages=1000
directory=/var/www/magento
user=www-data
autostart=true
autorestart=true
startretries=3
stderr_logfile=/var/log/magento/queue-event-trigger.err.log
stdout_logfile=/var/log/magento/queue-event-trigger.out.log
environment=HOME="/var/www/magento"

[program:magento-event-retry]
command=/usr/bin/php /var/www/magento/bin/magento queue:consumers:start event.retry.consumer --max-messages=1000
directory=/var/www/magento
user=www-data
autostart=true
autorestart=true
startretries=3
stderr_logfile=/var/log/magento/queue-event-retry.err.log
stdout_logfile=/var/log/magento/queue-event-retry.out.log
environment=HOME="/var/www/magento"

[program:magento-abandoned-cart]
command=/usr/bin/php /var/www/magento/bin/magento queue:consumers:start artlounge.abandoned.cart.consumer --max-messages=500
directory=/var/www/magento
user=www-data
autostart=true
autorestart=true
startretries=3
stderr_logfile=/var/log/magento/queue-abandoned-cart.err.log
stdout_logfile=/var/log/magento/queue-abandoned-cart.out.log
environment=HOME="/var/www/magento"
```

**Start Consumers:**
```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start all
```

**Verify Running:**
```bash
sudo supervisorctl status
```

Expected output:
```
magento-event-trigger            RUNNING   pid 12345, uptime 0:01:00
magento-event-retry              RUNNING   pid 12346, uptime 0:01:00
magento-abandoned-cart           RUNNING   pid 12347, uptime 0:01:00
```

### Option B: Systemd (Alternative)

**Create Service File:**
```bash
sudo nano /etc/systemd/system/magento-queue@.service
```

**Service Content:**
```ini
[Unit]
Description=Magento Queue Consumer - %i
After=network.target

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=/var/www/magento
ExecStart=/usr/bin/php /var/www/magento/bin/magento queue:consumers:start %i --max-messages=1000
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

**Enable and Start:**
```bash
sudo systemctl daemon-reload
sudo systemctl enable magento-queue@event.trigger.consumer
sudo systemctl enable magento-queue@event.retry.consumer
sudo systemctl start magento-queue@event.trigger.consumer
sudo systemctl start magento-queue@event.retry.consumer
```

### Option C: Cron-Based (Fallback)

If you cannot run persistent consumers, add to crontab:

```bash
crontab -e
```

Add:
```cron
* * * * * /usr/bin/php /var/www/magento/bin/magento cron:run >> /var/log/magento-cron.log 2>&1
```

And ensure `crontab.xml` is configured (already included in modules).

**Note:** Cron-based processing is slower and less reliable than queue consumers. Use only if RabbitMQ is unavailable.

---

## Verification

### Quick Post-Install Verification

```bash
# Verify modules enabled
php bin/magento module:status | grep -i bento

# Test Bento API connection
php bin/magento bento:test

# Check status (config + subscriptions)
php bin/magento bento:status
```

If all three pass, the integration is correctly installed. For deeper checks, continue with the steps below.

### Step 1: Verify Module Installation

```bash
# Check module status
bin/magento module:status | grep -E "(Aligent|ArtLounge)"

# Check for errors
bin/magento setup:upgrade --dry-run

# Verify configuration
bin/magento config:show artlounge_bento
```

### Step 2: Verify Queue Setup

```bash
# List consumers
bin/magento queue:consumers:list

# Check RabbitMQ queues
rabbitmqctl list_queues name messages consumers
```

Expected queues:
```
event.trigger           0       1
event.failover.retry    0       1
artlounge.abandoned     0       1
```

### Step 3: Test Order Event

1. **Place a test order** on the storefront
2. **Check queue processing:**
   ```bash
   # Watch queue in real-time
   watch -n 1 'rabbitmqctl list_queues name messages'
   ```
3. **Check logs:**
   ```bash
   tail -f var/log/bento.log
   ```
4. **Verify in Bento:**
   - Login to Bento dashboard
   - Check Events > Recent Events
   - Look for `$purchase` event with order details

### Step 4: Test Abandoned Cart

1. **Add items to cart** (as guest or logged in)
2. **Enter email** at checkout (don't complete order)
3. **Wait for configured delay** (or set to 1 minute for testing)
4. **Check queue processing**
5. **Verify in Bento:**
   - Look for `$abandoned` event

### Step 5: Test Client-Side Tracking

1. **Open browser developer tools** (F12)
2. **Enable debug mode**: Set `window.bentoDebug = true` in console, or enable Debug Mode in admin
3. **Visit a product page** - Look for `[Bento] Product view tracked` in console with product data
4. **Add to cart** - Look for `[Bento] Add to cart tracked`
5. **Check Bento dashboard** - Events should appear attributed to the customer's email (not "Visitor #...")

### Step 6: Verify Logs

Check Magento logs:
```bash
tail -f var/log/bento.log
tail -f var/log/system.log | grep -i bento
tail -f var/log/exception.log
```

Check queue consumer logs:
```bash
tail -f /var/log/magento/queue-event-trigger.out.log
```

---

## Troubleshooting

### Common Issues

#### Issue: Events Not Being Processed

**Symptoms:**
- Orders placed but no events in Bento
- Queue messages accumulating

**Solutions:**
1. Check if consumers are running:
   ```bash
   supervisorctl status
   ```
2. Check consumer logs:
   ```bash
   tail -f /var/log/magento/queue-event-trigger.err.log
   ```
3. Restart consumers:
   ```bash
   supervisorctl restart all
   ```

#### Issue: Events processed but not reaching Bento (silent failure)

**Symptoms:**
- Queue consumers running, messages being consumed
- No errors in `bento.log`
- But no events appear in Bento dashboard

**Root cause:** The `BentoNotifier` may not be registered with Aligent's `NotifierFactory`. This happens if the DI argument name in `BentoEvents/etc/di.xml` is wrong.

**Solution:** Verify the DI configuration:
```xml
<!-- In BentoEvents/etc/di.xml, this MUST say "notifierClasses" (not "notifiers"): -->
<type name="Aligent\AsyncEvents\Service\AsyncEvent\NotifierFactory">
    <arguments>
        <argument name="notifierClasses" xsi:type="array">
            <item name="bento" xsi:type="object">ArtLounge\BentoEvents\Model\BentoNotifier</item>
        </argument>
    </arguments>
</type>
```

If wrong, fix it and run `bin/magento cache:flush` (and `setup:di:compile` if in production mode).

#### Issue: Connection Test Fails

**Symptoms:**
- "Connection failed" error in admin

**Solutions:**
1. Verify API credentials in Bento dashboard
2. Check firewall (outbound HTTPS to bentonow.com):
   ```bash
   curl -v https://track.bentonow.com
   ```
3. Check for proxy settings in `env.php`

#### Issue: Abandoned Cart Not Triggering

**Symptoms:**
- Carts abandoned but no events sent

**Solutions:**
1. Verify email is captured (check quote table):
   ```sql
   SELECT entity_id, customer_email, items_count, grand_total
   FROM quote
   WHERE is_active = 1
   ORDER BY updated_at DESC
   LIMIT 10;
   ```
2. Check minimum cart value setting
3. Verify customer group not excluded
4. Check delay setting (maybe not enough time passed)

#### Issue: Duplicate Events

**Symptoms:**
- Same event appears multiple times in Bento

**Solutions:**
1. Check for multiple active subscriptions:
   ```sql
   SELECT * FROM async_event_subscriber WHERE status = 1;
   ```
2. Remove duplicates:
   ```sql
   DELETE FROM async_event_subscriber
   WHERE subscription_id NOT IN (
     SELECT MIN(subscription_id)
     FROM async_event_subscriber
     GROUP BY event_name, store_id
   );
   ```
3. Check for old Bento scripts in theme/CMS blocks (see DEVELOPER_HANDOFF.md section 1)

#### Issue: Client-side events show anonymous user

**Symptoms:**
- Events in Bento attributed to "Visitor #..." instead of customer email

**Solutions:**
1. Verify the `bento-identity` customerData section is working:
   - In browser console: `require(['Magento_Customer/js/customer-data'], function(cd) { console.log(cd.get('bento-identity')()); })`
   - Should return `{ email: "user@example.com" }` for logged-in users
2. Check that `BentoTracking/etc/frontend/di.xml` registers `BentoSection` in the section pool
3. Check that `BentoTracking/etc/frontend/sections.xml` invalidates `bento-identity` on `customer/account/loginPost`

#### Issue: High Memory Usage

**Symptoms:**
- Consumer processes using excessive memory

**Solutions:**
1. Reduce `--max-messages` parameter
2. Add memory limit:
   ```bash
   php -d memory_limit=512M bin/magento queue:consumers:start event.trigger.consumer
   ```
3. Increase restart frequency in Supervisor

### Debug Mode

Enable debug mode temporarily:

1. **Admin:** Stores > Configuration > ArtLounge > Bento > Debug Mode = Yes
2. **Check logs:** `var/log/bento.log`
3. **Browser console:** Set `window.bentoDebug = true` to see client-side debug output
4. **Remember to disable** after debugging

### Getting Help

1. **Check logs first:**
   - `var/log/bento.log`
   - `var/log/system.log`
   - `var/log/exception.log`

2. **Use CLI diagnostics:**
   ```bash
   bin/magento bento:test     # Test API connection
   bin/magento bento:status   # Check config + subscription status
   ```

3. **Contact support:**
   - Bento: jesse@bentonow.com or Discord
   - Art Lounge development team

---

## Post-Installation Checklist

### Immediate

- [ ] Modules installed and enabled
- [ ] Admin configuration completed
- [ ] Test connection successful
- [ ] Event subscriptions verified (9 in `async_event_subscriber` table)
- [ ] Queue consumers running
- [ ] Test order placed and event received in Bento
- [ ] Test abandoned cart triggered

### Within 24 Hours

- [ ] Monitor queue processing
- [ ] Verify no error logs
- [ ] Check Bento dashboard for incoming events
- [ ] Test all event types
- [ ] Verify client-side events show customer email (not anonymous)

### Within 1 Week

- [ ] Review event volume in Bento
- [ ] Adjust abandoned cart delay if needed
- [ ] Create first Bento flow using events
- [ ] Document any custom configurations

---

## Next Steps

1. **Proceed to** `03-ADMIN-CONFIGURATION-REFERENCE.md` for detailed admin settings
2. **Review** `DEVELOPER_HANDOFF.md` for known issues and bug fixes
3. **Run tests** as described in `05-TESTING-GUIDE.md`
4. **Set up Bento flows** using the incoming events

---

*Document prepared for Art Lounge Magento 2 Integration Project*
*Last updated: March 20, 2026*
