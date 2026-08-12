-- Azuronn Supabase schema.
--
-- Storage model:
--   * Public / static site content (settings, hero, products, categories, navigation,
--     footer, FAQ, news, reviews, celebs, diamond shapes, coupons, attribute profiles)
--     lives in app_state.key = 'site_content'. The same JSON shape is kept on disk
--     in data/site-content.json so static data does not need a database round-trip
--     to edit.
--   * Private / sensitive data lives in dedicated tables:
--       customers, orders, newsletter_subscribers, appointments,
--       admin_users, admin_requests, cart_sessions.
--   * Login lockout state lives in app_state.key = 'admin_login_attempts' and
--     'employee_admin_login_attempts'. Same key/value pattern as the public blob.

create table if not exists public.app_state (
    key text primary key,
    payload jsonb not null default '{}'::jsonb,
    updated_at timestamptz not null default timezone('utc', now())
);

create table if not exists public.cart_sessions (
    session_key text primary key,
    customer_id text null,
    payload jsonb not null default '{"items":[],"coupon_code":""}'::jsonb,
    updated_at timestamptz not null default timezone('utc', now())
);

create table if not exists public.media_assets (
    id bigserial primary key,
    public_url text not null unique,
    file_path text not null,
    file_name text not null,
    mime_type text not null,
    media_type text not null default 'file',
    file_size bigint not null default 0,
    source text not null default 'hosting',
    created_at timestamptz not null default timezone('utc', now()),
    updated_at timestamptz not null default timezone('utc', now())
);

-- ── Private data tables ────────────────────────────────────────────────────
-- All RLS is left enabled; the app uses the service role key for writes and the
-- service role is the only writer. Reads also use the service role so the data
-- never leaves the server.
--
-- Email columns are plain `text` plus a case-insensitive unique index built on
-- `lower(email)`. Supabase Postgres does not enable the `citext` extension by
-- default, and the inserts / lookups always pass `lower(...)` anyway, so this
-- gives the same guarantee without an extension dependency.

create table if not exists public.customers (
    id text primary key,
    email text not null,
    password_hash text not null default '',
    name text not null default '',
    phone text not null default '',
    city text not null default '',
    state text not null default '',
    country text not null default 'United Kingdom',
    postal_code text not null default '',
    address_line_1 text not null default '',
    address_line_2 text not null default '',
    status text not null default 'active',
    joined_at timestamptz null,
    last_order_at timestamptz null,
    total_orders integer not null default 0,
    total_spent numeric(12, 2) not null default 0,
    wishlist_product_ids jsonb not null default '[]'::jsonb,
    saved_addresses jsonb not null default '[]'::jsonb,
    notes text not null default '',
    created_at timestamptz not null default timezone('utc', now()),
    updated_at timestamptz not null default timezone('utc', now())
);
create unique index if not exists customers_email_lower_uidx on public.customers (lower(email));
create index if not exists customers_status_idx on public.customers (status);

create table if not exists public.orders (
    id text primary key,
    customer_id text null references public.customers (id) on delete set null,
    customer_email text not null default '',
    customer_name text not null default '',
    customer_phone text not null default '',
    status text not null default 'received',
    payment_method text not null default 'online',
    payment_status text not null default 'awaiting',
    payment_reference text not null default '',
    stripe_checkout_session_id text not null default '',
    stripe_payment_intent_id text not null default '',
    stripe_cancel_token text not null default '',
    refund_id text not null default '',
    refunded_amount text not null default '',
    refunded_at timestamptz null,
    cancelled_at timestamptz null,
    total text not null default '',
    subtotal text not null default '',
    discount_amount text not null default '',
    shipping_amount text not null default '',
    coupon_code text not null default '',
    item_count text not null default '',
    placed_at timestamptz null,
    delivered_at timestamptz null,
    shipping_address jsonb not null default '{}'::jsonb,
    customer_request jsonb not null default '{}'::jsonb,
    items jsonb not null default '[]'::jsonb,
    notes text not null default '',
    tracking_id text not null default '',
    created_at timestamptz not null default timezone('utc', now()),
    updated_at timestamptz not null default timezone('utc', now())
);
create index if not exists orders_customer_id_idx on public.orders (customer_id);
create index if not exists orders_customer_email_idx on public.orders (lower(customer_email));
create index if not exists orders_status_idx on public.orders (status);
create index if not exists orders_payment_status_idx on public.orders (payment_status);

create table if not exists public.newsletter_subscribers (
    id text primary key,
    subscribed_email text not null,
    account_customer_id text not null default '',
    account_holder_name text not null default '',
    account_holder_email text not null default '',
    source text not null default 'guest',
    status text not null default 'active',
    subscribed_at timestamptz null,
    updated_at timestamptz not null default timezone('utc', now())
);
create unique index if not exists newsletter_email_lower_uidx on public.newsletter_subscribers (lower(subscribed_email));
create index if not exists newsletter_status_idx on public.newsletter_subscribers (status);

create table if not exists public.appointments (
    id text primary key default 'appointments',
    config jsonb not null default '{}'::jsonb,
    bookings jsonb not null default '[]'::jsonb,
    updated_at timestamptz not null default timezone('utc', now())
);

create table if not exists public.admin_users (
    id text primary key,
    role text not null check (role in ('super', 'employee')),
    name text not null default '',
    username text not null,
    password_hash text not null default '',
    status text not null default 'active',
    created_at timestamptz not null default timezone('utc', now()),
    updated_at timestamptz not null default timezone('utc', now())
);
create unique index if not exists admin_users_username_lower_uidx on public.admin_users (lower(username));
create index if not exists admin_users_role_idx on public.admin_users (role);

create table if not exists public.admin_requests (
    id text primary key,
    action text not null default '',
    view text not null default 'dashboard',
    summary text not null default '',
    status text not null default 'pending',
    actor_portal text not null default 'employee',
    actor_name text not null default '',
    actor_username text not null default '',
    created_at timestamptz null,
    resolved_at timestamptz null,
    resolved_by text not null default '',
    note text not null default '',
    payload_hash text not null default '',
    details jsonb not null default '[]'::jsonb,
    payload jsonb not null default '{}'::jsonb
);
create index if not exists admin_requests_status_idx on public.admin_requests (status);
create index if not exists admin_requests_actor_username_idx on public.admin_requests (actor_username);

-- ── updated_at trigger ─────────────────────────────────────────────────────
create or replace function public.touch_updated_at()
returns trigger
language plpgsql
as $$
begin
    new.updated_at = timezone('utc', now());
    return new;
end;
$$;

drop trigger if exists touch_updated_at_app_state on public.app_state;
create trigger touch_updated_at_app_state
before update on public.app_state
for each row execute function public.touch_updated_at();

drop trigger if exists touch_updated_at_cart_sessions on public.cart_sessions;
create trigger touch_updated_at_cart_sessions
before update on public.cart_sessions
for each row execute function public.touch_updated_at();

drop trigger if exists touch_updated_at_media_assets on public.media_assets;
create trigger touch_updated_at_media_assets
before update on public.media_assets
for each row execute function public.touch_updated_at();

drop trigger if exists touch_updated_at_customers on public.customers;
create trigger touch_updated_at_customers
before update on public.customers
for each row execute function public.touch_updated_at();

drop trigger if exists touch_updated_at_orders on public.orders;
create trigger touch_updated_at_orders
before update on public.orders
for each row execute function public.touch_updated_at();

drop trigger if exists touch_updated_at_newsletter on public.newsletter_subscribers;
create trigger touch_updated_at_newsletter
before update on public.newsletter_subscribers
for each row execute function public.touch_updated_at();

drop trigger if exists touch_updated_at_appointments on public.appointments;
create trigger touch_updated_at_appointments
before update on public.appointments
for each row execute function public.touch_updated_at();

drop trigger if exists touch_updated_at_admin_users on public.admin_users;
create trigger touch_updated_at_admin_users
before update on public.admin_users
for each row execute function public.touch_updated_at();

drop trigger if exists touch_updated_at_admin_requests on public.admin_requests;
create trigger touch_updated_at_admin_requests
before update on public.admin_requests
for each row execute function public.touch_updated_at();

-- ── Row level security ─────────────────────────────────────────────────────
-- Only the service role should touch any of these tables. The publishable key
-- is used by the public site but it has no role here because RLS is on.
alter table public.app_state enable row level security;
alter table public.cart_sessions enable row level security;
alter table public.media_assets enable row level security;
alter table public.customers enable row level security;
alter table public.orders enable row level security;
alter table public.newsletter_subscribers enable row level security;
alter table public.appointments enable row level security;
alter table public.admin_users enable row level security;
alter table public.admin_requests enable row level security;

comment on table public.app_state is 'Application state records such as site_content and admin_login_attempts.';
comment on table public.cart_sessions is 'Persistent guest and customer cart snapshots keyed by a server-issued cart token.';
comment on table public.media_assets is 'Hosting-stored media metadata. Files stay on hosting disk; only URLs and metadata live here.';
comment on table public.customers is 'Private. Customer accounts, login credentials, saved addresses and wishlist.';
comment on table public.orders is 'Private. Order history, shipping address, payments and refunds.';
comment on table public.newsletter_subscribers is 'Private. Newsletter subscription list and consent flags.';
comment on table public.appointments is 'Private. Single-row config + bookings list for the showroom appointment scheduler.';
comment on table public.admin_users is 'Private. Super admin and employee admin accounts.';
comment on table public.admin_requests is 'Private. Employee → super admin approval queue.';
