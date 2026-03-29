--
-- PostgreSQL database dump
--

\restrict nJcBMh9ZfrmdnfOiHZq8XpVSndaNJLaJHXNwwqT0w2iiy0Kiu0xgDzoUhsQzJfi

-- Dumped from database version 16.10
-- Dumped by pg_dump version 16.10

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

SET default_tablespace = '';

SET default_table_access_method = heap;

--
-- Name: admin_announcements; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.admin_announcements (
    id bigint NOT NULL,
    title character varying(255) NOT NULL,
    message text NOT NULL,
    type character varying(255) DEFAULT 'info'::character varying NOT NULL,
    target character varying(255) DEFAULT 'all'::character varying NOT NULL,
    target_company_id bigint,
    is_active boolean DEFAULT true NOT NULL,
    expires_at timestamp(0) without time zone,
    created_by bigint NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    CONSTRAINT admin_announcements_target_check CHECK (((target)::text = ANY ((ARRAY['all'::character varying, 'specific'::character varying])::text[]))),
    CONSTRAINT admin_announcements_type_check CHECK (((type)::text = ANY ((ARRAY['info'::character varying, 'warning'::character varying, 'urgent'::character varying, 'success'::character varying])::text[])))
);


--
-- Name: admin_announcements_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.admin_announcements_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: admin_announcements_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.admin_announcements_id_seq OWNED BY public.admin_announcements.id;


--
-- Name: admin_audit_logs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.admin_audit_logs (
    id bigint NOT NULL,
    admin_id bigint,
    action character varying(255) NOT NULL,
    target_type character varying(255),
    target_id bigint,
    metadata json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: admin_audit_logs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.admin_audit_logs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: admin_audit_logs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.admin_audit_logs_id_seq OWNED BY public.admin_audit_logs.id;


--
-- Name: admin_users; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.admin_users (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    email character varying(255) NOT NULL,
    password character varying(255) NOT NULL,
    role character varying(30) DEFAULT 'admin'::character varying NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    remember_token character varying(100)
);


--
-- Name: admin_users_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.admin_users_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: admin_users_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.admin_users_id_seq OWNED BY public.admin_users.id;


--
-- Name: announcement_dismissals; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.announcement_dismissals (
    id bigint NOT NULL,
    announcement_id bigint NOT NULL,
    user_id bigint NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: announcement_dismissals_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.announcement_dismissals_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: announcement_dismissals_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.announcement_dismissals_id_seq OWNED BY public.announcement_dismissals.id;


--
-- Name: anomaly_logs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.anomaly_logs (
    id bigint NOT NULL,
    company_id bigint NOT NULL,
    type character varying(255) NOT NULL,
    severity character varying(255) DEFAULT 'warning'::character varying NOT NULL,
    description text NOT NULL,
    metadata json,
    resolved boolean DEFAULT false NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: anomaly_logs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.anomaly_logs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: anomaly_logs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.anomaly_logs_id_seq OWNED BY public.anomaly_logs.id;


--
-- Name: audit_logs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.audit_logs (
    id bigint NOT NULL,
    company_id bigint,
    user_id bigint,
    action character varying(255) NOT NULL,
    entity_type character varying(255) NOT NULL,
    entity_id bigint,
    old_values json,
    new_values json,
    ip_address character varying(255),
    sha256_hash character varying(255) NOT NULL,
    created_at timestamp(0) without time zone
);


--
-- Name: audit_logs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.audit_logs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: audit_logs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.audit_logs_id_seq OWNED BY public.audit_logs.id;


--
-- Name: branches; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.branches (
    id bigint NOT NULL,
    company_id bigint NOT NULL,
    name character varying(255) NOT NULL,
    address text,
    is_head_office boolean DEFAULT false NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    province character varying(100)
);


--
-- Name: branches_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.branches_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: branches_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.branches_id_seq OWNED BY public.branches.id;


--
-- Name: cache; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.cache (
    key character varying(255) NOT NULL,
    value text NOT NULL,
    expiration integer NOT NULL
);


--
-- Name: cache_locks; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.cache_locks (
    key character varying(255) NOT NULL,
    owner character varying(255) NOT NULL,
    expiration integer NOT NULL
);


--
-- Name: companies; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.companies (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    ntn character varying(255) NOT NULL,
    email character varying(255),
    phone character varying(255),
    address character varying(255),
    fbr_token character varying(255),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    compliance_score integer DEFAULT 100 NOT NULL,
    token_expires_at timestamp(0) without time zone,
    fbr_environment character varying(255) DEFAULT 'sandbox'::character varying NOT NULL,
    fbr_sandbox_token text,
    fbr_production_token text,
    fbr_registration_no character varying(255),
    fbr_business_name character varying(255),
    suspended_at timestamp(0) without time zone,
    company_status character varying(255) DEFAULT 'active'::character varying NOT NULL,
    token_expiry_date date,
    last_successful_submission timestamp(0) without time zone,
    fbr_connection_status character varying(255) DEFAULT 'unknown'::character varying,
    is_internal_account boolean DEFAULT false NOT NULL,
    onboarding_completed boolean DEFAULT false NOT NULL,
    standard_tax_rate numeric(5,2) DEFAULT '18'::numeric NOT NULL,
    sector_type character varying(255) DEFAULT 'Retail'::character varying NOT NULL,
    province character varying(255),
    invoice_number_prefix character varying(20),
    next_invoice_number integer DEFAULT 1 NOT NULL,
    fbr_sandbox_url character varying(500),
    fbr_production_url character varying(500),
    cnic character varying(20),
    business_activity character varying(255),
    owner_name character varying(255),
    invoice_limit_override integer,
    user_limit_override integer,
    branch_limit_override integer,
    registration_no character varying(100),
    mobile character varying(50),
    city character varying(100),
    website character varying(255),
    inventory_enabled boolean DEFAULT false NOT NULL,
    pra_reporting_enabled boolean DEFAULT false NOT NULL,
    pra_environment character varying(255) DEFAULT 'sandbox'::character varying NOT NULL,
    pra_pos_id character varying(255),
    pra_production_token character varying(255),
    receipt_printer_size character varying(10) DEFAULT '80mm'::character varying NOT NULL,
    status character varying(20) DEFAULT 'approved'::character varying NOT NULL,
    franchise_id bigint,
    logo_path character varying(255),
    pra_access_code character varying(255),
    confidential_pin character varying(255),
    next_local_invoice_number integer DEFAULT 1 NOT NULL,
    product_type character varying(10) DEFAULT 'di'::character varying NOT NULL,
    deleted_at timestamp(0) without time zone,
    deleted_reason character varying(255),
    pra_proxy_url character varying(255),
    force_watermark boolean DEFAULT false NOT NULL,
    fbr_pos_enabled boolean DEFAULT false NOT NULL,
    fbr_pos_id character varying(255),
    fbr_pos_token character varying(255),
    fbr_pos_environment character varying(255) DEFAULT 'sandbox'::character varying NOT NULL,
    fbr_reporting_enabled boolean DEFAULT false NOT NULL,
    kds_enabled boolean DEFAULT true NOT NULL,
    kitchen_printer_enabled boolean DEFAULT false NOT NULL,
    print_on_hold boolean DEFAULT false NOT NULL,
    print_on_pay boolean DEFAULT true NOT NULL,
    restaurant_mode boolean DEFAULT false NOT NULL,
    pos_type character varying(20) DEFAULT 'general'::character varying NOT NULL,
    manager_override_pin character varying(255),
    cashier_discount_limit numeric(5,2) DEFAULT '10'::numeric NOT NULL,
    manager_discount_limit numeric(5,2) DEFAULT '50'::numeric NOT NULL
);


--
-- Name: companies_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.companies_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: companies_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.companies_id_seq OWNED BY public.companies.id;


--
-- Name: company_usage_stats; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.company_usage_stats (
    id bigint NOT NULL,
    company_id bigint NOT NULL,
    total_pos_transactions integer DEFAULT 0 NOT NULL,
    total_sales_amount numeric(15,2) DEFAULT '0'::numeric NOT NULL,
    active_terminals integer DEFAULT 0 NOT NULL,
    active_users integer DEFAULT 0 NOT NULL,
    inventory_items integer DEFAULT 0 NOT NULL,
    last_activity_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: company_usage_stats_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.company_usage_stats_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: company_usage_stats_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.company_usage_stats_id_seq OWNED BY public.company_usage_stats.id;


--
-- Name: compliance_reports; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.compliance_reports (
    id bigint NOT NULL,
    company_id bigint NOT NULL,
    invoice_id bigint,
    rule_flags json,
    anomaly_flags json,
    final_score integer DEFAULT 100 NOT NULL,
    risk_level character varying(255) DEFAULT 'LOW'::character varying NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    is_fbr_validated boolean DEFAULT false NOT NULL,
    pre_validation_flags json
);


--
-- Name: compliance_reports_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.compliance_reports_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: compliance_reports_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.compliance_reports_id_seq OWNED BY public.compliance_reports.id;


--
-- Name: compliance_scores; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.compliance_scores (
    id bigint NOT NULL,
    company_id bigint NOT NULL,
    score integer DEFAULT 100 NOT NULL,
    success_rate double precision DEFAULT '100'::double precision NOT NULL,
    retry_ratio double precision DEFAULT '0'::double precision NOT NULL,
    draft_aging double precision DEFAULT '0'::double precision NOT NULL,
    failure_ratio double precision DEFAULT '0'::double precision NOT NULL,
    category character varying(255) DEFAULT 'SAFE'::character varying NOT NULL,
    calculated_date date NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: compliance_scores_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.compliance_scores_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: compliance_scores_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.compliance_scores_id_seq OWNED BY public.compliance_scores.id;


--
-- Name: customer_ledgers; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.customer_ledgers (
    id bigint NOT NULL,
    company_id bigint NOT NULL,
    customer_name character varying(255) NOT NULL,
    customer_ntn character varying(255),
    invoice_id bigint,
    debit numeric(15,2) DEFAULT '0'::numeric NOT NULL,
    credit numeric(15,2) DEFAULT '0'::numeric NOT NULL,
    balance_after numeric(15,2) DEFAULT '0'::numeric NOT NULL,
    type character varying(255) NOT NULL,
    notes text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: customer_ledgers_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.customer_ledgers_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: customer_ledgers_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.customer_ledgers_id_seq OWNED BY public.customer_ledgers.id;


--
-- Name: customer_profiles; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.customer_profiles (
    id bigint NOT NULL,
    company_id bigint NOT NULL,
    name character varying(255) NOT NULL,
    ntn character varying(50),
    cnic character varying(15),
    address text,
    phone character varying(50),
    email character varying(255),
    registration_type character varying(20) DEFAULT 'Unregistered'::character varying NOT NULL,
    is_active boolean DEFAULT true NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    province character varying(100)
);


--
-- Name: customer_profiles_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.customer_profiles_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: customer_profiles_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.customer_profiles_id_seq OWNED BY public.customer_profiles.id;


--
-- Name: customer_tax_rules; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.customer_tax_rules (
    id bigint NOT NULL,
    company_id bigint NOT NULL,
    customer_ntn character varying(255) NOT NULL,
    hs_code character varying(255) NOT NULL,
    override_tax_rate numeric(5,2),
    override_schedule_type character varying(255),
    override_sro_required boolean,
    override_mrp_required boolean,
    description text,
    is_active boolean DEFAULT true NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: customer_tax_rules_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.customer_tax_rules_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: customer_tax_rules_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.customer_tax_rules_id_seq OWNED BY public.customer_tax_rules.id;


--
-- Name: failed_jobs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.failed_jobs (
    id bigint NOT NULL,
    uuid character varying(255) NOT NULL,
    connection text NOT NULL,
    queue text NOT NULL,
    payload text NOT NULL,
    exception text NOT NULL,
    failed_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: failed_jobs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.failed_jobs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: failed_jobs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.failed_jobs_id_seq OWNED BY public.failed_jobs.id;


--
-- Name: fbr_day_close_reports; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.fbr_day_close_reports (
    id bigint NOT NULL,
    company_id bigint NOT NULL,
    report_date date NOT NULL,
    report_number character varying(50) NOT NULL,
    total_invoices integer DEFAULT 0 NOT NULL,
    fbr_invoices integer DEFAULT 0 NOT NULL,
    local_invoices integer DEFAULT 0 NOT NULL,
    failed_invoices integer DEFAULT 0 NOT NULL,
    gross_sales numeric(15,2) DEFAULT '0'::numeric NOT NULL,
    total_discount numeric(15,2) DEFAULT '0'::numeric NOT NULL,
    net_sales numeric(15,2) DEFAULT '0'::numeric NOT NULL,
    total_tax numeric(15,2) DEFAULT '0'::numeric NOT NULL,
    total_fbr_fee numeric(15,2) DEFAULT '0'::numeric NOT NULL,
    total_amount numeric(15,2) DEFAULT '0'::numeric NOT NULL,
    cash_amount numeric(15,2) DEFAULT '0'::numeric NOT NULL,
    card_amount numeric(15,2) DEFAULT '0'::numeric NOT NULL,
    other_amount numeric(15,2) DEFAULT '0'::numeric NOT NULL,
    first_invoice_number character varying(50),
    last_invoice_number character varying(50),
    first_invoice_time timestamp(0) without time zone,
    last_invoice_time timestamp(0) without time zone,
    closed_by bigint,
    notes text,
    hash character varying(64),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: fbr_day_close_reports_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.fbr_day_close_reports_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: fbr_day_close_reports_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.fbr_day_close_reports_id_seq OWNED BY public.fbr_day_close_reports.id;


--
-- Name: fbr_logs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.fbr_logs (
    id bigint NOT NULL,
    invoice_id bigint NOT NULL,
    request_payload text NOT NULL,
    response_payload text,
    status character varying(255) DEFAULT 'pending'::character varying NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    failure_type character varying(255),
    response_time_ms integer,
    retry_count integer DEFAULT 0 NOT NULL,
    environment_used character varying(20),
    failure_category character varying(50),
    submission_latency_ms integer,
    request_payload_hash character varying(255)
);


--
-- Name: fbr_logs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.fbr_logs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: fbr_logs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.fbr_logs_id_seq OWNED BY public.fbr_logs.id;


--
-- Name: fbr_pos_logs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.fbr_pos_logs (
    id bigint NOT NULL,
    company_id bigint NOT NULL,
    transaction_id bigint,
    request_payload json,
    response_payload json,
    response_code character varying(255),
    status character varying(255) DEFAULT 'pending'::character varying NOT NULL,
    error_message text,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: fbr_pos_logs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.fbr_pos_logs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: fbr_pos_logs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.fbr_pos_logs_id_seq OWNED BY public.fbr_pos_logs.id;


--
-- Name: fbr_pos_transaction_items; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.fbr_pos_transaction_items (
    id bigint NOT NULL,
    transaction_id bigint NOT NULL,
    product_id bigint,
    item_name character varying(255) NOT NULL,
    hs_code character varying(255),
    quantity integer DEFAULT 1 NOT NULL,
    unit_price numeric(15,2) DEFAULT '0'::numeric NOT NULL,
    discount numeric(15,2) DEFAULT '0'::numeric NOT NULL,
    tax_rate numeric(5,2) DEFAULT '0'::numeric NOT NULL,
    tax_amount numeric(15,2) DEFAULT '0'::numeric NOT NULL,
    subtotal numeric(15,2) DEFAULT '0'::numeric NOT NULL,
    total numeric(15,2) DEFAULT '0'::numeric NOT NULL,
    is_tax_exempt boolean DEFAULT false NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    uom character varying(10) DEFAULT 'U'::character varying NOT NULL
);


--
-- Name: fbr_pos_transaction_items_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.fbr_pos_transaction_items_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: fbr_pos_transaction_items_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.fbr_pos_transaction_items_id_seq OWNED BY public.fbr_pos_transaction_items.id;


--
-- Name: fbr_pos_transactions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.fbr_pos_transactions (
    id bigint NOT NULL,
    company_id bigint NOT NULL,
    invoice_number character varying(255) NOT NULL,
    customer_name character varying(255),
    customer_phone character varying(255),
    customer_ntn character varying(255),
    subtotal numeric(15,2) DEFAULT '0'::numeric NOT NULL,
    discount_type character varying(255),
    discount_value numeric(10,2) DEFAULT '0'::numeric NOT NULL,
    discount_amount numeric(15,2) DEFAULT '0'::numeric NOT NULL,
    tax_rate numeric(5,2) DEFAULT '0'::numeric NOT NULL,
    tax_amount numeric(15,2) DEFAULT '0'::numeric NOT NULL,
    total_amount numeric(15,2) DEFAULT '0'::numeric NOT NULL,
    payment_method character varying(255) DEFAULT 'cash'::character varying NOT NULL,
    status character varying(255) DEFAULT 'completed'::character varying NOT NULL,
    fbr_invoice_number character varying(255),
    fbr_status character varying(255) DEFAULT 'pending'::character varying NOT NULL,
    fbr_response_code character varying(255),
    fbr_response json,
    fbr_submission_hash character varying(255),
    created_by bigint,
    share_token character varying(64),
    share_token_created_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    invoice_mode character varying(10) DEFAULT 'fbr'::character varying NOT NULL,
    fbr_service_charge numeric(10,2) DEFAULT '0'::numeric NOT NULL
);


--
-- Name: fbr_pos_transactions_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.fbr_pos_transactions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: fbr_pos_transactions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.fbr_pos_transactions_id_seq OWNED BY public.fbr_pos_transactions.id;


--
-- Name: franchises; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.franchises (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    email character varying(255) NOT NULL,
    phone character varying(30),
    commission_rate numeric(5,2) DEFAULT '0'::numeric NOT NULL,
    status character varying(20) DEFAULT 'active'::character varying NOT NULL,
    password character varying(255),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: franchises_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.franchises_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: franchises_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.franchises_id_seq OWNED BY public.franchises.id;


--
-- Name: global_hs_master; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.global_hs_master (
    id bigint NOT NULL,
    hs_code character varying(20) NOT NULL,
    description character varying(500),
    pct_code character varying(30),
    schedule_type character varying(30) DEFAULT 'standard'::character varying NOT NULL,
    tax_rate numeric(5,2) DEFAULT '18'::numeric NOT NULL,
    default_uom character varying(100),
    sro_required boolean DEFAULT false NOT NULL,
    sro_number character varying(100),
    sro_item_serial_no character varying(100),
    mrp_required boolean DEFAULT false NOT NULL,
    sector_tag character varying(100),
    risk_weight numeric(5,2) DEFAULT '0'::numeric NOT NULL,
    mapping_status character varying(20) DEFAULT 'Mapped'::character varying NOT NULL,
    created_by bigint,
    updated_by bigint,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    st_withheld_applicable boolean DEFAULT false NOT NULL,
    petroleum_levy_applicable boolean DEFAULT false NOT NULL
);


--
-- Name: global_hs_master_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.global_hs_master_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: global_hs_master_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.global_hs_master_id_seq OWNED BY public.global_hs_master.id;


--
-- Name: hs_code_mappings; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.hs_code_mappings (
    id bigint NOT NULL,
    hs_code character varying(20) NOT NULL,
    label character varying(255),
    sale_type character varying(255) DEFAULT 'standard'::character varying NOT NULL,
    tax_rate numeric(8,2) DEFAULT '0'::numeric NOT NULL,
    sro_applicable boolean DEFAULT false NOT NULL,
    sro_number character varying(255),
    serial_number_applicable boolean DEFAULT false NOT NULL,
    serial_number_value character varying(255),
    mrp_required boolean DEFAULT false NOT NULL,
    pct_code character varying(255),
    default_uom character varying(255),
    buyer_type character varying(255),
    notes text,
    priority integer DEFAULT 10 NOT NULL,
    is_active boolean DEFAULT true NOT NULL,
    created_by bigint,
    updated_by bigint,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: hs_code_mappings_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.hs_code_mappings_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: hs_code_mappings_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.hs_code_mappings_id_seq OWNED BY public.hs_code_mappings.id;


--
-- Name: hs_intelligence_logs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.hs_intelligence_logs (
    id bigint NOT NULL,
    hs_code character varying(20) NOT NULL,
    suggested_schedule_type character varying(50),
    suggested_tax_rate numeric(5,2),
    suggested_sro_required boolean DEFAULT false NOT NULL,
    suggested_serial_required boolean DEFAULT false NOT NULL,
    suggested_mrp_required boolean DEFAULT false NOT NULL,
    confidence_score integer DEFAULT 0 NOT NULL,
    weight_breakdown json,
    based_on_records_count integer DEFAULT 0 NOT NULL,
    rejection_factor integer DEFAULT 0 NOT NULL,
    industry_factor integer DEFAULT 0 NOT NULL,
    created_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: hs_intelligence_logs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.hs_intelligence_logs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: hs_intelligence_logs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.hs_intelligence_logs_id_seq OWNED BY public.hs_intelligence_logs.id;


--
-- Name: hs_mapping_audit_logs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.hs_mapping_audit_logs (
    id bigint NOT NULL,
    hs_code_mapping_id bigint NOT NULL,
    action character varying(20) NOT NULL,
    field_name character varying(255),
    old_value text,
    new_value text,
    changed_by bigint,
    snapshot jsonb,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: hs_mapping_audit_logs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.hs_mapping_audit_logs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: hs_mapping_audit_logs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.hs_mapping_audit_logs_id_seq OWNED BY public.hs_mapping_audit_logs.id;


--
-- Name: hs_mapping_responses; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.hs_mapping_responses (
    id bigint NOT NULL,
    hs_code_mapping_id bigint NOT NULL,
    company_id bigint NOT NULL,
    user_id bigint NOT NULL,
    invoice_id bigint,
    hs_code character varying(20) NOT NULL,
    action character varying(255) NOT NULL,
    custom_values json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: hs_mapping_responses_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.hs_mapping_responses_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: hs_mapping_responses_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.hs_mapping_responses_id_seq OWNED BY public.hs_mapping_responses.id;


--
-- Name: hs_master_global; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.hs_master_global (
    id bigint NOT NULL,
    hs_code character varying(255) NOT NULL,
    description text,
    schedule_type character varying(255),
    default_tax_rate numeric(5,2),
    sro_required boolean DEFAULT false NOT NULL,
    default_sro_number character varying(255),
    serial_required boolean DEFAULT false NOT NULL,
    default_serial_no character varying(255),
    mrp_required boolean DEFAULT false NOT NULL,
    st_withheld_applicable boolean DEFAULT false NOT NULL,
    petroleum_levy_applicable boolean DEFAULT false NOT NULL,
    default_uom character varying(255),
    confidence_score integer DEFAULT 100 NOT NULL,
    last_source character varying(255) DEFAULT 'manual'::character varying NOT NULL,
    is_active boolean DEFAULT true NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: hs_master_global_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.hs_master_global_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: hs_master_global_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.hs_master_global_id_seq OWNED BY public.hs_master_global.id;


--
-- Name: hs_rejection_history; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.hs_rejection_history (
    id bigint NOT NULL,
    hs_code character varying(20) NOT NULL,
    rejection_count integer DEFAULT 0 NOT NULL,
    last_rejection_reason text,
    last_seen_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    error_code character varying(100),
    error_message text,
    last_rejected_at timestamp(0) without time zone,
    environment character varying(20) DEFAULT 'sandbox'::character varying NOT NULL
);


--
-- Name: hs_rejection_history_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.hs_rejection_history_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: hs_rejection_history_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.hs_rejection_history_id_seq OWNED BY public.hs_rejection_history.id;


--
-- Name: hs_unmapped_log; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.hs_unmapped_log (
    id bigint NOT NULL,
    hs_code character varying(20) NOT NULL,
    company_id bigint NOT NULL,
    invoice_id bigint,
    frequency_count integer DEFAULT 1 NOT NULL,
    first_seen_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    last_seen_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: hs_unmapped_log_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.hs_unmapped_log_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: hs_unmapped_log_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.hs_unmapped_log_id_seq OWNED BY public.hs_unmapped_log.id;


--
-- Name: hs_unmapped_queue; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.hs_unmapped_queue (
    id bigint NOT NULL,
    hs_code character varying(255) NOT NULL,
    company_id bigint NOT NULL,
    usage_count integer DEFAULT 1 NOT NULL,
    first_seen_at timestamp(0) without time zone,
    flagged_reason character varying(255),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: hs_unmapped_queue_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.hs_unmapped_queue_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: hs_unmapped_queue_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.hs_unmapped_queue_id_seq OWNED BY public.hs_unmapped_queue.id;


--
-- Name: hs_usage_patterns; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.hs_usage_patterns (
    id bigint NOT NULL,
    hs_code character varying(20) NOT NULL,
    schedule_type character varying(50),
    tax_rate numeric(5,2),
    sro_schedule_no character varying(100),
    sro_item_serial_no character varying(100),
    mrp_required boolean DEFAULT false NOT NULL,
    sale_type character varying(100),
    success_count integer DEFAULT 0 NOT NULL,
    rejection_count integer DEFAULT 0 NOT NULL,
    confidence_score numeric(5,2) DEFAULT '0'::numeric NOT NULL,
    admin_status character varying(20) DEFAULT 'auto'::character varying NOT NULL,
    last_used_at timestamp(0) without time zone,
    integrity_hash character varying(64),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: hs_usage_patterns_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.hs_usage_patterns_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: hs_usage_patterns_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.hs_usage_patterns_id_seq OWNED BY public.hs_usage_patterns.id;


--
-- Name: ingredients; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.ingredients (
    id bigint NOT NULL,
    company_id bigint NOT NULL,
    name character varying(255) NOT NULL,
    unit character varying(20) NOT NULL,
    cost_per_unit numeric(15,2) DEFAULT '0'::numeric NOT NULL,
    current_stock numeric(15,2) DEFAULT '0'::numeric NOT NULL,
    min_stock_level numeric(15,2) DEFAULT '0'::numeric NOT NULL,
    is_active boolean DEFAULT true NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: ingredients_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.ingredients_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: ingredients_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.ingredients_id_seq OWNED BY public.ingredients.id;


--
-- Name: inventory_adjustments; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.inventory_adjustments (
    id bigint NOT NULL,
    company_id bigint NOT NULL,
    product_id bigint NOT NULL,
    type character varying(30) NOT NULL,
    quantity numeric(15,2) NOT NULL,
    previous_quantity numeric(15,2) DEFAULT '0'::numeric NOT NULL,
    new_quantity numeric(15,2) DEFAULT '0'::numeric NOT NULL,
    reason character varying(255),
    notes text,
    created_by bigint,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: inventory_adjustments_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.inventory_adjustments_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: inventory_adjustments_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.inventory_adjustments_id_seq OWNED BY public.inventory_adjustments.id;


--
-- Name: inventory_movements; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.inventory_movements (
    id bigint NOT NULL,
    company_id bigint NOT NULL,
    product_id bigint NOT NULL,
    branch_id bigint,
    type character varying(30) NOT NULL,
    quantity numeric(15,2) NOT NULL,
    unit_price numeric(15,2) DEFAULT '0'::numeric NOT NULL,
    total_price numeric(15,2) DEFAULT '0'::numeric NOT NULL,
    balance_after numeric(15,2) DEFAULT '0'::numeric NOT NULL,
    reference_type character varying(50),
    reference_id bigint,
    reference_number character varying(255),
    notes text,
    created_by bigint,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: inventory_movements_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.inventory_movements_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: inventory_movements_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.inventory_movements_id_seq OWNED BY public.inventory_movements.id;


--
-- Name: inventory_stocks; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.inventory_stocks (
    id bigint NOT NULL,
    company_id bigint NOT NULL,
    product_id bigint NOT NULL,
    branch_id bigint,
    quantity numeric(15,2) DEFAULT '0'::numeric NOT NULL,
    min_stock_level numeric(15,2) DEFAULT '0'::numeric NOT NULL,
    max_stock_level numeric(15,2),
    avg_purchase_price numeric(15,2) DEFAULT '0'::numeric NOT NULL,
    last_purchase_price numeric(15,2) DEFAULT '0'::numeric NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: inventory_stocks_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.inventory_stocks_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: inventory_stocks_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.inventory_stocks_id_seq OWNED BY public.inventory_stocks.id;


--
-- Name: invoice_activity_logs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.invoice_activity_logs (
    id bigint NOT NULL,
    invoice_id bigint NOT NULL,
    company_id bigint NOT NULL,
    user_id bigint,
    action character varying(255) NOT NULL,
    changes_json json,
    ip_address character varying(45),
    created_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: invoice_activity_logs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.invoice_activity_logs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: invoice_activity_logs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.invoice_activity_logs_id_seq OWNED BY public.invoice_activity_logs.id;


--
-- Name: invoice_items; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.invoice_items (
    id bigint NOT NULL,
    invoice_id bigint NOT NULL,
    hs_code character varying(255) NOT NULL,
    description character varying(255) NOT NULL,
    quantity numeric(10,2) NOT NULL,
    price numeric(15,2) NOT NULL,
    tax numeric(15,2) NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    schedule_type character varying(50) DEFAULT 'standard'::character varying NOT NULL,
    pct_code character varying(50),
    tax_rate numeric(8,2) DEFAULT '18'::numeric NOT NULL,
    sro_schedule_no character varying(100),
    serial_no character varying(100),
    mrp numeric(15,2),
    default_uom character varying(255) DEFAULT 'Numbers, pieces, units'::character varying NOT NULL,
    sale_type character varying(255) DEFAULT 'Goods at standard rate (default)'::character varying NOT NULL,
    st_withheld_at_source boolean DEFAULT false NOT NULL,
    petroleum_levy numeric(18,2),
    further_tax numeric(12,2) DEFAULT 0
);


--
-- Name: invoice_items_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.invoice_items_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: invoice_items_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.invoice_items_id_seq OWNED BY public.invoice_items.id;


--
-- Name: invoices; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.invoices (
    id bigint NOT NULL,
    company_id bigint NOT NULL,
    invoice_number character varying(255),
    status character varying(255) DEFAULT 'draft'::character varying NOT NULL,
    buyer_name character varying(255) NOT NULL,
    buyer_ntn character varying(255),
    total_amount numeric(15,2) DEFAULT '0'::numeric NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    integrity_hash character varying(64),
    override_reason text,
    override_by bigint,
    submission_mode character varying(255),
    fbr_invoice_id character varying(255),
    qr_data text,
    share_uuid uuid,
    branch_id bigint,
    internal_invoice_number character varying(255),
    fbr_invoice_number character varying(255),
    fbr_submission_date timestamp(0) without time zone,
    document_type character varying(50) DEFAULT 'Sale Invoice'::character varying NOT NULL,
    reference_invoice_number character varying(255),
    buyer_registration_type character varying(50) DEFAULT 'Registered'::character varying NOT NULL,
    supplier_province character varying(100),
    destination_province character varying(100),
    total_value_excluding_st numeric(18,2) DEFAULT '0'::numeric NOT NULL,
    total_sales_tax numeric(18,2) DEFAULT '0'::numeric NOT NULL,
    wht_rate numeric(8,4) DEFAULT '0'::numeric NOT NULL,
    wht_amount numeric(18,2) DEFAULT '0'::numeric NOT NULL,
    net_receivable numeric(18,2) DEFAULT '0'::numeric NOT NULL,
    fbr_status character varying(50),
    invoice_date character varying(255),
    buyer_cnic character varying(15),
    buyer_address text,
    submitted_at timestamp(0) without time zone,
    fbr_submission_hash character varying(255),
    is_fbr_processing boolean DEFAULT false NOT NULL,
    wht_locked boolean DEFAULT false NOT NULL
);


--
-- Name: invoices_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.invoices_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: invoices_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.invoices_id_seq OWNED BY public.invoices.id;


--
-- Name: job_batches; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.job_batches (
    id character varying(255) NOT NULL,
    name character varying(255) NOT NULL,
    total_jobs integer NOT NULL,
    pending_jobs integer NOT NULL,
    failed_jobs integer NOT NULL,
    failed_job_ids text NOT NULL,
    options text,
    cancelled_at integer,
    created_at integer NOT NULL,
    finished_at integer
);


--
-- Name: jobs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.jobs (
    id bigint NOT NULL,
    queue character varying(255) NOT NULL,
    payload text NOT NULL,
    attempts smallint NOT NULL,
    reserved_at integer,
    available_at integer NOT NULL,
    created_at integer NOT NULL
);


--
-- Name: jobs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.jobs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: jobs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.jobs_id_seq OWNED BY public.jobs.id;


--
-- Name: migrations; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.migrations (
    id integer NOT NULL,
    migration character varying(255) NOT NULL,
    batch integer NOT NULL
);


--
-- Name: migrations_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.migrations_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: migrations_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.migrations_id_seq OWNED BY public.migrations.id;


--
-- Name: notifications; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.notifications (
    id bigint NOT NULL,
    company_id bigint,
    user_id bigint,
    type character varying(255) NOT NULL,
    title character varying(255) NOT NULL,
    message text NOT NULL,
    read boolean DEFAULT false NOT NULL,
    metadata json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: notifications_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.notifications_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: notifications_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.notifications_id_seq OWNED BY public.notifications.id;


--
-- Name: override_logs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.override_logs (
    id bigint NOT NULL,
    invoice_id bigint NOT NULL,
    company_id bigint NOT NULL,
    user_id bigint NOT NULL,
    action character varying(255) NOT NULL,
    reason text NOT NULL,
    metadata json,
    ip_address character varying(255),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: override_logs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.override_logs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: override_logs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.override_logs_id_seq OWNED BY public.override_logs.id;


--
-- Name: override_usage_logs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.override_usage_logs (
    id bigint NOT NULL,
    company_id bigint NOT NULL,
    invoice_id bigint,
    hs_code character varying(255) NOT NULL,
    override_layer character varying(255) NOT NULL,
    override_source_id character varying(255),
    original_values json,
    overridden_values json,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: override_usage_logs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.override_usage_logs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: override_usage_logs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.override_usage_logs_id_seq OWNED BY public.override_usage_logs.id;


--
-- Name: password_reset_otps; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.password_reset_otps (
    id bigint NOT NULL,
    email character varying(255) NOT NULL,
    otp character varying(6) NOT NULL,
    expires_at timestamp(0) without time zone NOT NULL,
    used boolean DEFAULT false NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    token character varying(64)
);


--
-- Name: password_reset_otps_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.password_reset_otps_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: password_reset_otps_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.password_reset_otps_id_seq OWNED BY public.password_reset_otps.id;


--
-- Name: password_reset_tokens; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.password_reset_tokens (
    email character varying(255) NOT NULL,
    token character varying(255) NOT NULL,
    created_at timestamp(0) without time zone
);


--
-- Name: pos_customers; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.pos_customers (
    id bigint NOT NULL,
    company_id bigint NOT NULL,
    name character varying(255) NOT NULL,
    email character varying(255),
    phone character varying(255),
    address text,
    city character varying(255),
    ntn character varying(255),
    cnic character varying(255),
    type character varying(255) DEFAULT 'unregistered'::character varying NOT NULL,
    is_active boolean DEFAULT true NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: pos_customers_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.pos_customers_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: pos_customers_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.pos_customers_id_seq OWNED BY public.pos_customers.id;


--
-- Name: pos_day_close_reports; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.pos_day_close_reports (
    id bigint NOT NULL,
    company_id bigint NOT NULL,
    report_date date NOT NULL,
    report_number character varying(50) NOT NULL,
    total_invoices integer DEFAULT 0 NOT NULL,
    pra_invoices integer DEFAULT 0 NOT NULL,
    local_invoices integer DEFAULT 0 NOT NULL,
    offline_invoices integer DEFAULT 0 NOT NULL,
    gross_sales numeric(15,2) DEFAULT '0'::numeric NOT NULL,
    total_discount numeric(15,2) DEFAULT '0'::numeric NOT NULL,
    net_sales numeric(15,2) DEFAULT '0'::numeric NOT NULL,
    total_tax numeric(15,2) DEFAULT '0'::numeric NOT NULL,
    total_amount numeric(15,2) DEFAULT '0'::numeric NOT NULL,
    cash_amount numeric(15,2) DEFAULT '0'::numeric NOT NULL,
    card_amount numeric(15,2) DEFAULT '0'::numeric NOT NULL,
    other_amount numeric(15,2) DEFAULT '0'::numeric NOT NULL,
    first_invoice_number character varying(50),
    last_invoice_number character varying(50),
    first_invoice_time timestamp(0) without time zone,
    last_invoice_time timestamp(0) without time zone,
    closed_by bigint,
    notes text,
    hash character varying(64),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: pos_day_close_reports_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.pos_day_close_reports_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: pos_day_close_reports_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.pos_day_close_reports_id_seq OWNED BY public.pos_day_close_reports.id;


--
-- Name: pos_payments; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.pos_payments (
    id bigint NOT NULL,
    transaction_id bigint NOT NULL,
    payment_method character varying(255) NOT NULL,
    amount numeric(15,2) DEFAULT '0'::numeric NOT NULL,
    reference_number character varying(255),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: pos_payments_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.pos_payments_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: pos_payments_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.pos_payments_id_seq OWNED BY public.pos_payments.id;


--
-- Name: pos_products; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.pos_products (
    id bigint NOT NULL,
    company_id bigint NOT NULL,
    name character varying(255) NOT NULL,
    description text,
    price numeric(12,2) DEFAULT '0'::numeric NOT NULL,
    tax_rate numeric(5,2) DEFAULT '0'::numeric NOT NULL,
    hs_code character varying(255),
    uom character varying(255) DEFAULT 'NOS'::character varying,
    category character varying(255),
    sku character varying(255),
    barcode character varying(255),
    is_active boolean DEFAULT true NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    is_tax_exempt boolean DEFAULT false NOT NULL,
    image character varying(255)
);


--
-- Name: pos_products_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.pos_products_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: pos_products_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.pos_products_id_seq OWNED BY public.pos_products.id;


--
-- Name: pos_services; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.pos_services (
    id bigint NOT NULL,
    company_id bigint NOT NULL,
    name character varying(255) NOT NULL,
    description text,
    price numeric(15,2) DEFAULT '0'::numeric NOT NULL,
    tax_rate numeric(5,2) DEFAULT '0'::numeric NOT NULL,
    is_active boolean DEFAULT true NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    is_tax_exempt boolean DEFAULT false NOT NULL
);


--
-- Name: pos_services_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.pos_services_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: pos_services_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.pos_services_id_seq OWNED BY public.pos_services.id;


--
-- Name: pos_tax_rules; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.pos_tax_rules (
    id bigint NOT NULL,
    payment_method character varying(255) NOT NULL,
    tax_rate numeric(5,2) NOT NULL,
    is_active boolean DEFAULT true NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: pos_tax_rules_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.pos_tax_rules_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: pos_tax_rules_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.pos_tax_rules_id_seq OWNED BY public.pos_tax_rules.id;


--
-- Name: pos_terminals; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.pos_terminals (
    id bigint NOT NULL,
    company_id bigint NOT NULL,
    terminal_name character varying(255) NOT NULL,
    terminal_code character varying(255) NOT NULL,
    location character varying(255),
    is_active boolean DEFAULT true NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: pos_terminals_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.pos_terminals_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: pos_terminals_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.pos_terminals_id_seq OWNED BY public.pos_terminals.id;


--
-- Name: pos_transaction_items; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.pos_transaction_items (
    id bigint NOT NULL,
    transaction_id bigint NOT NULL,
    item_type character varying(255) DEFAULT 'product'::character varying NOT NULL,
    item_id bigint,
    item_name character varying(255) NOT NULL,
    quantity integer DEFAULT 1 NOT NULL,
    unit_price numeric(15,2) DEFAULT '0'::numeric NOT NULL,
    subtotal numeric(15,2) DEFAULT '0'::numeric NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    is_tax_exempt boolean DEFAULT false NOT NULL,
    tax_rate numeric(8,2) DEFAULT '0'::numeric NOT NULL,
    tax_amount numeric(12,2) DEFAULT '0'::numeric NOT NULL,
    item_discount_type character varying(20),
    item_discount_value numeric(10,2) DEFAULT '0'::numeric NOT NULL,
    item_discount_amount numeric(10,2) DEFAULT '0'::numeric NOT NULL,
    CONSTRAINT pos_transaction_items_item_type_check CHECK (((item_type)::text = ANY ((ARRAY['product'::character varying, 'service'::character varying])::text[])))
);


--
-- Name: pos_transaction_items_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.pos_transaction_items_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: pos_transaction_items_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.pos_transaction_items_id_seq OWNED BY public.pos_transaction_items.id;


--
-- Name: pos_transactions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.pos_transactions (
    id bigint NOT NULL,
    company_id bigint NOT NULL,
    terminal_id bigint,
    invoice_number character varying(255) NOT NULL,
    customer_name character varying(255),
    customer_phone character varying(255),
    subtotal numeric(15,2) DEFAULT '0'::numeric NOT NULL,
    discount_type character varying(255) DEFAULT 'percentage'::character varying NOT NULL,
    discount_value numeric(15,2) DEFAULT '0'::numeric NOT NULL,
    discount_amount numeric(15,2) DEFAULT '0'::numeric NOT NULL,
    tax_rate numeric(5,2) DEFAULT '0'::numeric NOT NULL,
    tax_amount numeric(15,2) DEFAULT '0'::numeric NOT NULL,
    total_amount numeric(15,2) DEFAULT '0'::numeric NOT NULL,
    payment_method character varying(255) DEFAULT 'cash'::character varying NOT NULL,
    pra_invoice_number character varying(255),
    pra_response_code character varying(255),
    pra_status character varying(255),
    created_by bigint,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    submission_hash character varying(255),
    pra_qr_code text,
    status character varying(255) DEFAULT 'completed'::character varying NOT NULL,
    locked_by_terminal_id bigint,
    lock_time timestamp(0) without time zone,
    exempt_amount numeric(12,2) DEFAULT '0'::numeric NOT NULL,
    share_token character varying(64),
    share_token_created_at timestamp(0) without time zone,
    invoice_mode character varying(10) DEFAULT 'pra'::character varying NOT NULL,
    receipt_printed_at timestamp(0) without time zone,
    reprint_count smallint DEFAULT '0'::smallint NOT NULL,
    CONSTRAINT pos_transactions_discount_type_check CHECK (((discount_type)::text = ANY ((ARRAY['percentage'::character varying, 'amount'::character varying])::text[])))
);


--
-- Name: pos_transactions_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.pos_transactions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: pos_transactions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.pos_transactions_id_seq OWNED BY public.pos_transactions.id;


--
-- Name: pra_logs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.pra_logs (
    id bigint NOT NULL,
    company_id bigint NOT NULL,
    transaction_id bigint,
    request_payload json,
    response_payload json,
    response_code character varying(255),
    status character varying(255) DEFAULT 'pending'::character varying NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: pra_logs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.pra_logs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: pra_logs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.pra_logs_id_seq OWNED BY public.pra_logs.id;


--
-- Name: pricing_plans; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.pricing_plans (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    invoice_limit integer NOT NULL,
    price numeric(10,2) NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    user_limit integer,
    branch_limit integer,
    is_trial boolean DEFAULT false NOT NULL,
    features text,
    max_terminals integer,
    max_users integer,
    max_products integer,
    inventory_enabled boolean DEFAULT true NOT NULL,
    reports_enabled boolean DEFAULT true NOT NULL,
    price_monthly numeric(12,2),
    product_type character varying(20) DEFAULT 'di'::character varying
);


--
-- Name: pricing_plans_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.pricing_plans_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: pricing_plans_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.pricing_plans_id_seq OWNED BY public.pricing_plans.id;


--
-- Name: product_recipes; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.product_recipes (
    id bigint NOT NULL,
    company_id bigint NOT NULL,
    product_id bigint NOT NULL,
    ingredient_id bigint NOT NULL,
    quantity_needed numeric(10,4) NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: product_recipes_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.product_recipes_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: product_recipes_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.product_recipes_id_seq OWNED BY public.product_recipes.id;


--
-- Name: products; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.products (
    id bigint NOT NULL,
    company_id bigint NOT NULL,
    name character varying(255) NOT NULL,
    hs_code character varying(255) NOT NULL,
    pct_code character varying(255),
    default_tax_rate numeric(5,2) DEFAULT '18'::numeric NOT NULL,
    uom character varying(255) DEFAULT 'PCS'::character varying NOT NULL,
    schedule_type character varying(255),
    sro_reference character varying(255),
    default_price numeric(15,2) DEFAULT '0'::numeric NOT NULL,
    is_active boolean DEFAULT true NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    serial_number character varying(100),
    mrp numeric(14,2)
);


--
-- Name: products_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.products_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: products_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.products_id_seq OWNED BY public.products.id;


--
-- Name: province_tax_rules; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.province_tax_rules (
    id bigint NOT NULL,
    province character varying(255) NOT NULL,
    hs_code character varying(255) NOT NULL,
    override_tax_rate numeric(5,2),
    override_schedule_type character varying(255),
    override_sro_required boolean,
    override_mrp_required boolean,
    description text,
    is_active boolean DEFAULT true NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: province_tax_rules_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.province_tax_rules_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: province_tax_rules_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.province_tax_rules_id_seq OWNED BY public.province_tax_rules.id;


--
-- Name: purchase_order_items; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.purchase_order_items (
    id bigint NOT NULL,
    purchase_order_id bigint NOT NULL,
    product_id bigint NOT NULL,
    quantity numeric(15,2) NOT NULL,
    unit_price numeric(15,2) DEFAULT '0'::numeric NOT NULL,
    total_price numeric(15,2) DEFAULT '0'::numeric NOT NULL,
    received_quantity numeric(15,2) DEFAULT '0'::numeric NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: purchase_order_items_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.purchase_order_items_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: purchase_order_items_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.purchase_order_items_id_seq OWNED BY public.purchase_order_items.id;


--
-- Name: purchase_orders; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.purchase_orders (
    id bigint NOT NULL,
    company_id bigint NOT NULL,
    supplier_id bigint,
    branch_id bigint,
    po_number character varying(50) NOT NULL,
    status character varying(20) DEFAULT 'draft'::character varying NOT NULL,
    order_date date NOT NULL,
    expected_date date,
    received_date date,
    total_amount numeric(15,2) DEFAULT '0'::numeric NOT NULL,
    notes text,
    created_by bigint,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: purchase_orders_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.purchase_orders_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: purchase_orders_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.purchase_orders_id_seq OWNED BY public.purchase_orders.id;


--
-- Name: restaurant_floors; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.restaurant_floors (
    id bigint NOT NULL,
    company_id bigint NOT NULL,
    name character varying(255) NOT NULL,
    sort_order integer DEFAULT 0 NOT NULL,
    is_active boolean DEFAULT true NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: restaurant_floors_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.restaurant_floors_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: restaurant_floors_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.restaurant_floors_id_seq OWNED BY public.restaurant_floors.id;


--
-- Name: restaurant_order_items; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.restaurant_order_items (
    id bigint NOT NULL,
    order_id bigint NOT NULL,
    item_type character varying(20) DEFAULT 'product'::character varying NOT NULL,
    item_id bigint NOT NULL,
    item_name character varying(255) NOT NULL,
    quantity numeric(10,2) DEFAULT '1'::numeric NOT NULL,
    unit_price numeric(15,2) NOT NULL,
    subtotal numeric(15,2) NOT NULL,
    special_notes character varying(255),
    is_tax_exempt boolean DEFAULT false NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    item_discount_type character varying(20),
    item_discount_value numeric(10,2) DEFAULT '0'::numeric NOT NULL,
    item_discount_amount numeric(10,2) DEFAULT '0'::numeric NOT NULL
);


--
-- Name: restaurant_order_items_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.restaurant_order_items_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: restaurant_order_items_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.restaurant_order_items_id_seq OWNED BY public.restaurant_order_items.id;


--
-- Name: restaurant_orders; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.restaurant_orders (
    id bigint NOT NULL,
    company_id bigint NOT NULL,
    order_number character varying(30) NOT NULL,
    table_id bigint,
    order_type character varying(20) DEFAULT 'dine_in'::character varying NOT NULL,
    status character varying(20) DEFAULT 'held'::character varying NOT NULL,
    customer_id bigint,
    customer_name character varying(255),
    customer_phone character varying(30),
    subtotal numeric(15,2) DEFAULT '0'::numeric NOT NULL,
    discount_amount numeric(15,2) DEFAULT '0'::numeric NOT NULL,
    tax_amount numeric(15,2) DEFAULT '0'::numeric NOT NULL,
    total_amount numeric(15,2) DEFAULT '0'::numeric NOT NULL,
    payment_method character varying(30),
    kitchen_notes text,
    pos_transaction_id bigint,
    created_by bigint NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    discount_type character varying(255),
    discount_value numeric(10,2) DEFAULT '0'::numeric NOT NULL,
    priority boolean DEFAULT false NOT NULL,
    estimated_cost numeric(10,2) DEFAULT '0'::numeric NOT NULL
);


--
-- Name: restaurant_orders_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.restaurant_orders_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: restaurant_orders_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.restaurant_orders_id_seq OWNED BY public.restaurant_orders.id;


--
-- Name: restaurant_tables; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.restaurant_tables (
    id bigint NOT NULL,
    company_id bigint NOT NULL,
    floor_id bigint NOT NULL,
    table_number character varying(20) NOT NULL,
    seats integer DEFAULT 4 NOT NULL,
    status character varying(20) DEFAULT 'available'::character varying NOT NULL,
    locked_by_user_id bigint,
    locked_at timestamp(0) without time zone,
    reservation_name character varying(255),
    reservation_time timestamp(0) without time zone,
    sort_order integer DEFAULT 0 NOT NULL,
    is_active boolean DEFAULT true NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: restaurant_tables_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.restaurant_tables_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: restaurant_tables_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.restaurant_tables_id_seq OWNED BY public.restaurant_tables.id;


--
-- Name: sector_tax_rules; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.sector_tax_rules (
    id bigint NOT NULL,
    sector_type character varying(255) NOT NULL,
    hs_code character varying(255) NOT NULL,
    override_tax_rate numeric(5,2),
    override_schedule_type character varying(255),
    override_sro_required boolean,
    override_mrp_required boolean,
    description text,
    is_active boolean DEFAULT true NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: sector_tax_rules_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.sector_tax_rules_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: sector_tax_rules_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.sector_tax_rules_id_seq OWNED BY public.sector_tax_rules.id;


--
-- Name: security_logs; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.security_logs (
    id bigint NOT NULL,
    user_id bigint,
    action character varying(255) NOT NULL,
    ip_address character varying(45),
    user_agent text,
    metadata json,
    created_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL
);


--
-- Name: security_logs_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.security_logs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: security_logs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.security_logs_id_seq OWNED BY public.security_logs.id;


--
-- Name: sessions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.sessions (
    id character varying(255) NOT NULL,
    user_id bigint,
    ip_address character varying(45),
    user_agent text,
    payload text NOT NULL,
    last_activity integer NOT NULL
);


--
-- Name: special_sro_rules; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.special_sro_rules (
    id bigint NOT NULL,
    hs_code character varying(255) NOT NULL,
    schedule_type character varying(255) NOT NULL,
    sro_number character varying(255) NOT NULL,
    serial_no character varying(255),
    applicable_sector character varying(255),
    applicable_province character varying(255),
    concessionary_rate numeric(5,2),
    description text,
    effective_from date,
    effective_until date,
    is_active boolean DEFAULT true NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: special_sro_rules_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.special_sro_rules_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: special_sro_rules_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.special_sro_rules_id_seq OWNED BY public.special_sro_rules.id;


--
-- Name: subscription_invoices; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.subscription_invoices (
    id bigint NOT NULL,
    subscription_id bigint NOT NULL,
    company_id bigint NOT NULL,
    amount numeric(12,2) NOT NULL,
    status character varying(20) DEFAULT 'pending'::character varying NOT NULL,
    due_date date NOT NULL,
    paid_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: subscription_invoices_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.subscription_invoices_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: subscription_invoices_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.subscription_invoices_id_seq OWNED BY public.subscription_invoices.id;


--
-- Name: subscription_payments; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.subscription_payments (
    id bigint NOT NULL,
    subscription_invoice_id bigint NOT NULL,
    amount numeric(12,2) NOT NULL,
    payment_method character varying(50),
    transaction_ref character varying(255),
    paid_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: subscription_payments_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.subscription_payments_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: subscription_payments_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.subscription_payments_id_seq OWNED BY public.subscription_payments.id;


--
-- Name: subscriptions; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.subscriptions (
    id bigint NOT NULL,
    company_id bigint NOT NULL,
    pricing_plan_id bigint NOT NULL,
    start_date date NOT NULL,
    end_date date NOT NULL,
    active boolean DEFAULT true NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    trial_ends_at timestamp(0) without time zone,
    billing_cycle character varying(20) DEFAULT 'monthly'::character varying NOT NULL,
    discount_percent numeric(5,2) DEFAULT '0'::numeric NOT NULL,
    final_price numeric(12,2)
);


--
-- Name: subscriptions_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.subscriptions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: subscriptions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.subscriptions_id_seq OWNED BY public.subscriptions.id;


--
-- Name: suppliers; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.suppliers (
    id bigint NOT NULL,
    company_id bigint NOT NULL,
    name character varying(255) NOT NULL,
    ntn character varying(50),
    cnic character varying(20),
    phone character varying(50),
    email character varying(255),
    address character varying(255),
    city character varying(100),
    contact_person character varying(255),
    notes text,
    is_active boolean DEFAULT true NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: suppliers_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.suppliers_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: suppliers_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.suppliers_id_seq OWNED BY public.suppliers.id;


--
-- Name: system_controls; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.system_controls (
    id bigint NOT NULL,
    key character varying(255) NOT NULL,
    value character varying(255) DEFAULT 'enabled'::character varying NOT NULL,
    description character varying(255),
    updated_by bigint,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: system_controls_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.system_controls_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: system_controls_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.system_controls_id_seq OWNED BY public.system_controls.id;


--
-- Name: system_settings; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.system_settings (
    id bigint NOT NULL,
    key character varying(255) NOT NULL,
    value text NOT NULL,
    description character varying(255),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: system_settings_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.system_settings_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: system_settings_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.system_settings_id_seq OWNED BY public.system_settings.id;


--
-- Name: users; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.users (
    id bigint NOT NULL,
    name character varying(255) NOT NULL,
    email character varying(255) NOT NULL,
    email_verified_at timestamp(0) without time zone,
    password character varying(255) NOT NULL,
    remember_token character varying(100),
    company_id bigint,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone,
    role character varying(255) DEFAULT 'employee'::character varying NOT NULL,
    is_active boolean DEFAULT true NOT NULL,
    dark_mode boolean,
    phone character varying(20),
    username character varying(100),
    pos_role character varying(20)
);


--
-- Name: users_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.users_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: users_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.users_id_seq OWNED BY public.users.id;


--
-- Name: vendor_risk_profiles; Type: TABLE; Schema: public; Owner: -
--

CREATE TABLE public.vendor_risk_profiles (
    id bigint NOT NULL,
    company_id bigint NOT NULL,
    vendor_ntn character varying(255) NOT NULL,
    vendor_name character varying(255),
    vendor_score integer DEFAULT 100 NOT NULL,
    total_invoices integer DEFAULT 0 NOT NULL,
    rejected_invoices integer DEFAULT 0 NOT NULL,
    tax_mismatches integer DEFAULT 0 NOT NULL,
    anomaly_count integer DEFAULT 0 NOT NULL,
    last_flagged_at timestamp(0) without time zone,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


--
-- Name: vendor_risk_profiles_id_seq; Type: SEQUENCE; Schema: public; Owner: -
--

CREATE SEQUENCE public.vendor_risk_profiles_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


--
-- Name: vendor_risk_profiles_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: -
--

ALTER SEQUENCE public.vendor_risk_profiles_id_seq OWNED BY public.vendor_risk_profiles.id;


--
-- Name: admin_announcements id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.admin_announcements ALTER COLUMN id SET DEFAULT nextval('public.admin_announcements_id_seq'::regclass);


--
-- Name: admin_audit_logs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.admin_audit_logs ALTER COLUMN id SET DEFAULT nextval('public.admin_audit_logs_id_seq'::regclass);


--
-- Name: admin_users id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.admin_users ALTER COLUMN id SET DEFAULT nextval('public.admin_users_id_seq'::regclass);


--
-- Name: announcement_dismissals id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.announcement_dismissals ALTER COLUMN id SET DEFAULT nextval('public.announcement_dismissals_id_seq'::regclass);


--
-- Name: anomaly_logs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.anomaly_logs ALTER COLUMN id SET DEFAULT nextval('public.anomaly_logs_id_seq'::regclass);


--
-- Name: audit_logs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.audit_logs ALTER COLUMN id SET DEFAULT nextval('public.audit_logs_id_seq'::regclass);


--
-- Name: branches id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.branches ALTER COLUMN id SET DEFAULT nextval('public.branches_id_seq'::regclass);


--
-- Name: companies id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.companies ALTER COLUMN id SET DEFAULT nextval('public.companies_id_seq'::regclass);


--
-- Name: company_usage_stats id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.company_usage_stats ALTER COLUMN id SET DEFAULT nextval('public.company_usage_stats_id_seq'::regclass);


--
-- Name: compliance_reports id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.compliance_reports ALTER COLUMN id SET DEFAULT nextval('public.compliance_reports_id_seq'::regclass);


--
-- Name: compliance_scores id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.compliance_scores ALTER COLUMN id SET DEFAULT nextval('public.compliance_scores_id_seq'::regclass);


--
-- Name: customer_ledgers id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customer_ledgers ALTER COLUMN id SET DEFAULT nextval('public.customer_ledgers_id_seq'::regclass);


--
-- Name: customer_profiles id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customer_profiles ALTER COLUMN id SET DEFAULT nextval('public.customer_profiles_id_seq'::regclass);


--
-- Name: customer_tax_rules id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customer_tax_rules ALTER COLUMN id SET DEFAULT nextval('public.customer_tax_rules_id_seq'::regclass);


--
-- Name: failed_jobs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.failed_jobs ALTER COLUMN id SET DEFAULT nextval('public.failed_jobs_id_seq'::regclass);


--
-- Name: fbr_day_close_reports id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fbr_day_close_reports ALTER COLUMN id SET DEFAULT nextval('public.fbr_day_close_reports_id_seq'::regclass);


--
-- Name: fbr_logs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fbr_logs ALTER COLUMN id SET DEFAULT nextval('public.fbr_logs_id_seq'::regclass);


--
-- Name: fbr_pos_logs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fbr_pos_logs ALTER COLUMN id SET DEFAULT nextval('public.fbr_pos_logs_id_seq'::regclass);


--
-- Name: fbr_pos_transaction_items id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fbr_pos_transaction_items ALTER COLUMN id SET DEFAULT nextval('public.fbr_pos_transaction_items_id_seq'::regclass);


--
-- Name: fbr_pos_transactions id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fbr_pos_transactions ALTER COLUMN id SET DEFAULT nextval('public.fbr_pos_transactions_id_seq'::regclass);


--
-- Name: franchises id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.franchises ALTER COLUMN id SET DEFAULT nextval('public.franchises_id_seq'::regclass);


--
-- Name: global_hs_master id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.global_hs_master ALTER COLUMN id SET DEFAULT nextval('public.global_hs_master_id_seq'::regclass);


--
-- Name: hs_code_mappings id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hs_code_mappings ALTER COLUMN id SET DEFAULT nextval('public.hs_code_mappings_id_seq'::regclass);


--
-- Name: hs_intelligence_logs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hs_intelligence_logs ALTER COLUMN id SET DEFAULT nextval('public.hs_intelligence_logs_id_seq'::regclass);


--
-- Name: hs_mapping_audit_logs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hs_mapping_audit_logs ALTER COLUMN id SET DEFAULT nextval('public.hs_mapping_audit_logs_id_seq'::regclass);


--
-- Name: hs_mapping_responses id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hs_mapping_responses ALTER COLUMN id SET DEFAULT nextval('public.hs_mapping_responses_id_seq'::regclass);


--
-- Name: hs_master_global id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hs_master_global ALTER COLUMN id SET DEFAULT nextval('public.hs_master_global_id_seq'::regclass);


--
-- Name: hs_rejection_history id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hs_rejection_history ALTER COLUMN id SET DEFAULT nextval('public.hs_rejection_history_id_seq'::regclass);


--
-- Name: hs_unmapped_log id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hs_unmapped_log ALTER COLUMN id SET DEFAULT nextval('public.hs_unmapped_log_id_seq'::regclass);


--
-- Name: hs_unmapped_queue id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hs_unmapped_queue ALTER COLUMN id SET DEFAULT nextval('public.hs_unmapped_queue_id_seq'::regclass);


--
-- Name: hs_usage_patterns id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hs_usage_patterns ALTER COLUMN id SET DEFAULT nextval('public.hs_usage_patterns_id_seq'::regclass);


--
-- Name: ingredients id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ingredients ALTER COLUMN id SET DEFAULT nextval('public.ingredients_id_seq'::regclass);


--
-- Name: inventory_adjustments id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.inventory_adjustments ALTER COLUMN id SET DEFAULT nextval('public.inventory_adjustments_id_seq'::regclass);


--
-- Name: inventory_movements id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.inventory_movements ALTER COLUMN id SET DEFAULT nextval('public.inventory_movements_id_seq'::regclass);


--
-- Name: inventory_stocks id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.inventory_stocks ALTER COLUMN id SET DEFAULT nextval('public.inventory_stocks_id_seq'::regclass);


--
-- Name: invoice_activity_logs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.invoice_activity_logs ALTER COLUMN id SET DEFAULT nextval('public.invoice_activity_logs_id_seq'::regclass);


--
-- Name: invoice_items id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.invoice_items ALTER COLUMN id SET DEFAULT nextval('public.invoice_items_id_seq'::regclass);


--
-- Name: invoices id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.invoices ALTER COLUMN id SET DEFAULT nextval('public.invoices_id_seq'::regclass);


--
-- Name: jobs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.jobs ALTER COLUMN id SET DEFAULT nextval('public.jobs_id_seq'::regclass);


--
-- Name: migrations id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.migrations ALTER COLUMN id SET DEFAULT nextval('public.migrations_id_seq'::regclass);


--
-- Name: notifications id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notifications ALTER COLUMN id SET DEFAULT nextval('public.notifications_id_seq'::regclass);


--
-- Name: override_logs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.override_logs ALTER COLUMN id SET DEFAULT nextval('public.override_logs_id_seq'::regclass);


--
-- Name: override_usage_logs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.override_usage_logs ALTER COLUMN id SET DEFAULT nextval('public.override_usage_logs_id_seq'::regclass);


--
-- Name: password_reset_otps id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.password_reset_otps ALTER COLUMN id SET DEFAULT nextval('public.password_reset_otps_id_seq'::regclass);


--
-- Name: pos_customers id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pos_customers ALTER COLUMN id SET DEFAULT nextval('public.pos_customers_id_seq'::regclass);


--
-- Name: pos_day_close_reports id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pos_day_close_reports ALTER COLUMN id SET DEFAULT nextval('public.pos_day_close_reports_id_seq'::regclass);


--
-- Name: pos_payments id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pos_payments ALTER COLUMN id SET DEFAULT nextval('public.pos_payments_id_seq'::regclass);


--
-- Name: pos_products id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pos_products ALTER COLUMN id SET DEFAULT nextval('public.pos_products_id_seq'::regclass);


--
-- Name: pos_services id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pos_services ALTER COLUMN id SET DEFAULT nextval('public.pos_services_id_seq'::regclass);


--
-- Name: pos_tax_rules id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pos_tax_rules ALTER COLUMN id SET DEFAULT nextval('public.pos_tax_rules_id_seq'::regclass);


--
-- Name: pos_terminals id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pos_terminals ALTER COLUMN id SET DEFAULT nextval('public.pos_terminals_id_seq'::regclass);


--
-- Name: pos_transaction_items id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pos_transaction_items ALTER COLUMN id SET DEFAULT nextval('public.pos_transaction_items_id_seq'::regclass);


--
-- Name: pos_transactions id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pos_transactions ALTER COLUMN id SET DEFAULT nextval('public.pos_transactions_id_seq'::regclass);


--
-- Name: pra_logs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pra_logs ALTER COLUMN id SET DEFAULT nextval('public.pra_logs_id_seq'::regclass);


--
-- Name: pricing_plans id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pricing_plans ALTER COLUMN id SET DEFAULT nextval('public.pricing_plans_id_seq'::regclass);


--
-- Name: product_recipes id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.product_recipes ALTER COLUMN id SET DEFAULT nextval('public.product_recipes_id_seq'::regclass);


--
-- Name: products id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.products ALTER COLUMN id SET DEFAULT nextval('public.products_id_seq'::regclass);


--
-- Name: province_tax_rules id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.province_tax_rules ALTER COLUMN id SET DEFAULT nextval('public.province_tax_rules_id_seq'::regclass);


--
-- Name: purchase_order_items id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.purchase_order_items ALTER COLUMN id SET DEFAULT nextval('public.purchase_order_items_id_seq'::regclass);


--
-- Name: purchase_orders id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.purchase_orders ALTER COLUMN id SET DEFAULT nextval('public.purchase_orders_id_seq'::regclass);


--
-- Name: restaurant_floors id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.restaurant_floors ALTER COLUMN id SET DEFAULT nextval('public.restaurant_floors_id_seq'::regclass);


--
-- Name: restaurant_order_items id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.restaurant_order_items ALTER COLUMN id SET DEFAULT nextval('public.restaurant_order_items_id_seq'::regclass);


--
-- Name: restaurant_orders id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.restaurant_orders ALTER COLUMN id SET DEFAULT nextval('public.restaurant_orders_id_seq'::regclass);


--
-- Name: restaurant_tables id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.restaurant_tables ALTER COLUMN id SET DEFAULT nextval('public.restaurant_tables_id_seq'::regclass);


--
-- Name: sector_tax_rules id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sector_tax_rules ALTER COLUMN id SET DEFAULT nextval('public.sector_tax_rules_id_seq'::regclass);


--
-- Name: security_logs id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.security_logs ALTER COLUMN id SET DEFAULT nextval('public.security_logs_id_seq'::regclass);


--
-- Name: special_sro_rules id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.special_sro_rules ALTER COLUMN id SET DEFAULT nextval('public.special_sro_rules_id_seq'::regclass);


--
-- Name: subscription_invoices id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subscription_invoices ALTER COLUMN id SET DEFAULT nextval('public.subscription_invoices_id_seq'::regclass);


--
-- Name: subscription_payments id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subscription_payments ALTER COLUMN id SET DEFAULT nextval('public.subscription_payments_id_seq'::regclass);


--
-- Name: subscriptions id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subscriptions ALTER COLUMN id SET DEFAULT nextval('public.subscriptions_id_seq'::regclass);


--
-- Name: suppliers id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.suppliers ALTER COLUMN id SET DEFAULT nextval('public.suppliers_id_seq'::regclass);


--
-- Name: system_controls id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.system_controls ALTER COLUMN id SET DEFAULT nextval('public.system_controls_id_seq'::regclass);


--
-- Name: system_settings id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.system_settings ALTER COLUMN id SET DEFAULT nextval('public.system_settings_id_seq'::regclass);


--
-- Name: users id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users ALTER COLUMN id SET DEFAULT nextval('public.users_id_seq'::regclass);


--
-- Name: vendor_risk_profiles id; Type: DEFAULT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.vendor_risk_profiles ALTER COLUMN id SET DEFAULT nextval('public.vendor_risk_profiles_id_seq'::regclass);


--
-- Name: admin_announcements admin_announcements_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.admin_announcements
    ADD CONSTRAINT admin_announcements_pkey PRIMARY KEY (id);


--
-- Name: admin_audit_logs admin_audit_logs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.admin_audit_logs
    ADD CONSTRAINT admin_audit_logs_pkey PRIMARY KEY (id);


--
-- Name: admin_users admin_users_email_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.admin_users
    ADD CONSTRAINT admin_users_email_unique UNIQUE (email);


--
-- Name: admin_users admin_users_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.admin_users
    ADD CONSTRAINT admin_users_pkey PRIMARY KEY (id);


--
-- Name: announcement_dismissals announcement_dismissals_announcement_id_user_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.announcement_dismissals
    ADD CONSTRAINT announcement_dismissals_announcement_id_user_id_unique UNIQUE (announcement_id, user_id);


--
-- Name: announcement_dismissals announcement_dismissals_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.announcement_dismissals
    ADD CONSTRAINT announcement_dismissals_pkey PRIMARY KEY (id);


--
-- Name: anomaly_logs anomaly_logs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.anomaly_logs
    ADD CONSTRAINT anomaly_logs_pkey PRIMARY KEY (id);


--
-- Name: audit_logs audit_logs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.audit_logs
    ADD CONSTRAINT audit_logs_pkey PRIMARY KEY (id);


--
-- Name: branches branches_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.branches
    ADD CONSTRAINT branches_pkey PRIMARY KEY (id);


--
-- Name: cache_locks cache_locks_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cache_locks
    ADD CONSTRAINT cache_locks_pkey PRIMARY KEY (key);


--
-- Name: cache cache_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.cache
    ADD CONSTRAINT cache_pkey PRIMARY KEY (key);


--
-- Name: companies companies_ntn_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.companies
    ADD CONSTRAINT companies_ntn_unique UNIQUE (ntn);


--
-- Name: companies companies_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.companies
    ADD CONSTRAINT companies_pkey PRIMARY KEY (id);


--
-- Name: company_usage_stats company_usage_stats_company_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.company_usage_stats
    ADD CONSTRAINT company_usage_stats_company_id_unique UNIQUE (company_id);


--
-- Name: company_usage_stats company_usage_stats_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.company_usage_stats
    ADD CONSTRAINT company_usage_stats_pkey PRIMARY KEY (id);


--
-- Name: compliance_reports compliance_reports_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.compliance_reports
    ADD CONSTRAINT compliance_reports_pkey PRIMARY KEY (id);


--
-- Name: compliance_scores compliance_scores_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.compliance_scores
    ADD CONSTRAINT compliance_scores_pkey PRIMARY KEY (id);


--
-- Name: customer_ledgers customer_ledgers_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customer_ledgers
    ADD CONSTRAINT customer_ledgers_pkey PRIMARY KEY (id);


--
-- Name: customer_profiles customer_profiles_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customer_profiles
    ADD CONSTRAINT customer_profiles_pkey PRIMARY KEY (id);


--
-- Name: customer_tax_rules customer_tax_rules_company_id_customer_ntn_hs_code_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customer_tax_rules
    ADD CONSTRAINT customer_tax_rules_company_id_customer_ntn_hs_code_unique UNIQUE (company_id, customer_ntn, hs_code);


--
-- Name: customer_tax_rules customer_tax_rules_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customer_tax_rules
    ADD CONSTRAINT customer_tax_rules_pkey PRIMARY KEY (id);


--
-- Name: failed_jobs failed_jobs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.failed_jobs
    ADD CONSTRAINT failed_jobs_pkey PRIMARY KEY (id);


--
-- Name: failed_jobs failed_jobs_uuid_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.failed_jobs
    ADD CONSTRAINT failed_jobs_uuid_unique UNIQUE (uuid);


--
-- Name: fbr_day_close_reports fbr_day_close_reports_company_id_report_date_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fbr_day_close_reports
    ADD CONSTRAINT fbr_day_close_reports_company_id_report_date_unique UNIQUE (company_id, report_date);


--
-- Name: fbr_day_close_reports fbr_day_close_reports_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fbr_day_close_reports
    ADD CONSTRAINT fbr_day_close_reports_pkey PRIMARY KEY (id);


--
-- Name: fbr_logs fbr_logs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fbr_logs
    ADD CONSTRAINT fbr_logs_pkey PRIMARY KEY (id);


--
-- Name: fbr_pos_logs fbr_pos_logs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fbr_pos_logs
    ADD CONSTRAINT fbr_pos_logs_pkey PRIMARY KEY (id);


--
-- Name: fbr_pos_transaction_items fbr_pos_transaction_items_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fbr_pos_transaction_items
    ADD CONSTRAINT fbr_pos_transaction_items_pkey PRIMARY KEY (id);


--
-- Name: fbr_pos_transactions fbr_pos_transactions_fbr_submission_hash_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fbr_pos_transactions
    ADD CONSTRAINT fbr_pos_transactions_fbr_submission_hash_unique UNIQUE (fbr_submission_hash);


--
-- Name: fbr_pos_transactions fbr_pos_transactions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fbr_pos_transactions
    ADD CONSTRAINT fbr_pos_transactions_pkey PRIMARY KEY (id);


--
-- Name: fbr_pos_transactions fbr_pos_transactions_share_token_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fbr_pos_transactions
    ADD CONSTRAINT fbr_pos_transactions_share_token_unique UNIQUE (share_token);


--
-- Name: franchises franchises_email_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.franchises
    ADD CONSTRAINT franchises_email_unique UNIQUE (email);


--
-- Name: franchises franchises_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.franchises
    ADD CONSTRAINT franchises_pkey PRIMARY KEY (id);


--
-- Name: global_hs_master global_hs_master_hs_code_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.global_hs_master
    ADD CONSTRAINT global_hs_master_hs_code_unique UNIQUE (hs_code);


--
-- Name: global_hs_master global_hs_master_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.global_hs_master
    ADD CONSTRAINT global_hs_master_pkey PRIMARY KEY (id);


--
-- Name: hs_code_mappings hs_code_mappings_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hs_code_mappings
    ADD CONSTRAINT hs_code_mappings_pkey PRIMARY KEY (id);


--
-- Name: hs_intelligence_logs hs_intelligence_logs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hs_intelligence_logs
    ADD CONSTRAINT hs_intelligence_logs_pkey PRIMARY KEY (id);


--
-- Name: hs_mapping_audit_logs hs_mapping_audit_logs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hs_mapping_audit_logs
    ADD CONSTRAINT hs_mapping_audit_logs_pkey PRIMARY KEY (id);


--
-- Name: hs_mapping_responses hs_mapping_responses_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hs_mapping_responses
    ADD CONSTRAINT hs_mapping_responses_pkey PRIMARY KEY (id);


--
-- Name: hs_master_global hs_master_global_hs_code_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hs_master_global
    ADD CONSTRAINT hs_master_global_hs_code_unique UNIQUE (hs_code);


--
-- Name: hs_master_global hs_master_global_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hs_master_global
    ADD CONSTRAINT hs_master_global_pkey PRIMARY KEY (id);


--
-- Name: hs_rejection_history hs_rejection_history_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hs_rejection_history
    ADD CONSTRAINT hs_rejection_history_pkey PRIMARY KEY (id);


--
-- Name: hs_unmapped_log hs_unmapped_log_hs_code_company_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hs_unmapped_log
    ADD CONSTRAINT hs_unmapped_log_hs_code_company_id_unique UNIQUE (hs_code, company_id);


--
-- Name: hs_unmapped_log hs_unmapped_log_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hs_unmapped_log
    ADD CONSTRAINT hs_unmapped_log_pkey PRIMARY KEY (id);


--
-- Name: hs_unmapped_queue hs_unmapped_queue_hs_code_company_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hs_unmapped_queue
    ADD CONSTRAINT hs_unmapped_queue_hs_code_company_id_unique UNIQUE (hs_code, company_id);


--
-- Name: hs_unmapped_queue hs_unmapped_queue_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hs_unmapped_queue
    ADD CONSTRAINT hs_unmapped_queue_pkey PRIMARY KEY (id);


--
-- Name: hs_usage_patterns hs_usage_patterns_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hs_usage_patterns
    ADD CONSTRAINT hs_usage_patterns_pkey PRIMARY KEY (id);


--
-- Name: hs_usage_patterns hs_usage_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hs_usage_patterns
    ADD CONSTRAINT hs_usage_unique UNIQUE (hs_code, schedule_type, tax_rate);


--
-- Name: ingredients ingredients_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ingredients
    ADD CONSTRAINT ingredients_pkey PRIMARY KEY (id);


--
-- Name: inventory_stocks inv_stock_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.inventory_stocks
    ADD CONSTRAINT inv_stock_unique UNIQUE (company_id, product_id, branch_id);


--
-- Name: inventory_adjustments inventory_adjustments_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.inventory_adjustments
    ADD CONSTRAINT inventory_adjustments_pkey PRIMARY KEY (id);


--
-- Name: inventory_movements inventory_movements_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.inventory_movements
    ADD CONSTRAINT inventory_movements_pkey PRIMARY KEY (id);


--
-- Name: inventory_stocks inventory_stocks_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.inventory_stocks
    ADD CONSTRAINT inventory_stocks_pkey PRIMARY KEY (id);


--
-- Name: invoice_activity_logs invoice_activity_logs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.invoice_activity_logs
    ADD CONSTRAINT invoice_activity_logs_pkey PRIMARY KEY (id);


--
-- Name: invoice_items invoice_items_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.invoice_items
    ADD CONSTRAINT invoice_items_pkey PRIMARY KEY (id);


--
-- Name: invoices invoices_company_internal_number_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.invoices
    ADD CONSTRAINT invoices_company_internal_number_unique UNIQUE (company_id, internal_invoice_number);


--
-- Name: invoices invoices_fbr_invoice_number_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.invoices
    ADD CONSTRAINT invoices_fbr_invoice_number_unique UNIQUE (fbr_invoice_number);


--
-- Name: invoices invoices_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.invoices
    ADD CONSTRAINT invoices_pkey PRIMARY KEY (id);


--
-- Name: invoices invoices_share_uuid_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.invoices
    ADD CONSTRAINT invoices_share_uuid_unique UNIQUE (share_uuid);


--
-- Name: job_batches job_batches_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.job_batches
    ADD CONSTRAINT job_batches_pkey PRIMARY KEY (id);


--
-- Name: jobs jobs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.jobs
    ADD CONSTRAINT jobs_pkey PRIMARY KEY (id);


--
-- Name: migrations migrations_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.migrations
    ADD CONSTRAINT migrations_pkey PRIMARY KEY (id);


--
-- Name: notifications notifications_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notifications
    ADD CONSTRAINT notifications_pkey PRIMARY KEY (id);


--
-- Name: override_logs override_logs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.override_logs
    ADD CONSTRAINT override_logs_pkey PRIMARY KEY (id);


--
-- Name: override_usage_logs override_usage_logs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.override_usage_logs
    ADD CONSTRAINT override_usage_logs_pkey PRIMARY KEY (id);


--
-- Name: password_reset_otps password_reset_otps_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.password_reset_otps
    ADD CONSTRAINT password_reset_otps_pkey PRIMARY KEY (id);


--
-- Name: password_reset_tokens password_reset_tokens_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.password_reset_tokens
    ADD CONSTRAINT password_reset_tokens_pkey PRIMARY KEY (email);


--
-- Name: pos_customers pos_customers_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pos_customers
    ADD CONSTRAINT pos_customers_pkey PRIMARY KEY (id);


--
-- Name: pos_day_close_reports pos_day_close_reports_company_id_report_date_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pos_day_close_reports
    ADD CONSTRAINT pos_day_close_reports_company_id_report_date_unique UNIQUE (company_id, report_date);


--
-- Name: pos_day_close_reports pos_day_close_reports_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pos_day_close_reports
    ADD CONSTRAINT pos_day_close_reports_pkey PRIMARY KEY (id);


--
-- Name: pos_payments pos_payments_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pos_payments
    ADD CONSTRAINT pos_payments_pkey PRIMARY KEY (id);


--
-- Name: pos_products pos_products_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pos_products
    ADD CONSTRAINT pos_products_pkey PRIMARY KEY (id);


--
-- Name: pos_services pos_services_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pos_services
    ADD CONSTRAINT pos_services_pkey PRIMARY KEY (id);


--
-- Name: pos_tax_rules pos_tax_rules_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pos_tax_rules
    ADD CONSTRAINT pos_tax_rules_pkey PRIMARY KEY (id);


--
-- Name: pos_terminals pos_terminals_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pos_terminals
    ADD CONSTRAINT pos_terminals_pkey PRIMARY KEY (id);


--
-- Name: pos_terminals pos_terminals_terminal_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pos_terminals
    ADD CONSTRAINT pos_terminals_terminal_id_unique UNIQUE (terminal_code);


--
-- Name: pos_transaction_items pos_transaction_items_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pos_transaction_items
    ADD CONSTRAINT pos_transaction_items_pkey PRIMARY KEY (id);


--
-- Name: pos_transactions pos_transactions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pos_transactions
    ADD CONSTRAINT pos_transactions_pkey PRIMARY KEY (id);


--
-- Name: pos_transactions pos_transactions_share_token_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pos_transactions
    ADD CONSTRAINT pos_transactions_share_token_unique UNIQUE (share_token);


--
-- Name: pra_logs pra_logs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pra_logs
    ADD CONSTRAINT pra_logs_pkey PRIMARY KEY (id);


--
-- Name: pricing_plans pricing_plans_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pricing_plans
    ADD CONSTRAINT pricing_plans_pkey PRIMARY KEY (id);


--
-- Name: product_recipes product_recipes_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.product_recipes
    ADD CONSTRAINT product_recipes_pkey PRIMARY KEY (id);


--
-- Name: product_recipes product_recipes_product_id_ingredient_id_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.product_recipes
    ADD CONSTRAINT product_recipes_product_id_ingredient_id_unique UNIQUE (product_id, ingredient_id);


--
-- Name: products products_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.products
    ADD CONSTRAINT products_pkey PRIMARY KEY (id);


--
-- Name: province_tax_rules province_tax_rules_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.province_tax_rules
    ADD CONSTRAINT province_tax_rules_pkey PRIMARY KEY (id);


--
-- Name: province_tax_rules province_tax_rules_province_hs_code_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.province_tax_rules
    ADD CONSTRAINT province_tax_rules_province_hs_code_unique UNIQUE (province, hs_code);


--
-- Name: purchase_order_items purchase_order_items_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.purchase_order_items
    ADD CONSTRAINT purchase_order_items_pkey PRIMARY KEY (id);


--
-- Name: purchase_orders purchase_orders_company_id_po_number_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.purchase_orders
    ADD CONSTRAINT purchase_orders_company_id_po_number_unique UNIQUE (company_id, po_number);


--
-- Name: purchase_orders purchase_orders_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.purchase_orders
    ADD CONSTRAINT purchase_orders_pkey PRIMARY KEY (id);


--
-- Name: restaurant_floors restaurant_floors_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.restaurant_floors
    ADD CONSTRAINT restaurant_floors_pkey PRIMARY KEY (id);


--
-- Name: restaurant_order_items restaurant_order_items_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.restaurant_order_items
    ADD CONSTRAINT restaurant_order_items_pkey PRIMARY KEY (id);


--
-- Name: restaurant_orders restaurant_orders_order_number_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.restaurant_orders
    ADD CONSTRAINT restaurant_orders_order_number_unique UNIQUE (order_number);


--
-- Name: restaurant_orders restaurant_orders_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.restaurant_orders
    ADD CONSTRAINT restaurant_orders_pkey PRIMARY KEY (id);


--
-- Name: restaurant_tables restaurant_tables_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.restaurant_tables
    ADD CONSTRAINT restaurant_tables_pkey PRIMARY KEY (id);


--
-- Name: sector_tax_rules sector_tax_rules_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sector_tax_rules
    ADD CONSTRAINT sector_tax_rules_pkey PRIMARY KEY (id);


--
-- Name: sector_tax_rules sector_tax_rules_sector_type_hs_code_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sector_tax_rules
    ADD CONSTRAINT sector_tax_rules_sector_type_hs_code_unique UNIQUE (sector_type, hs_code);


--
-- Name: security_logs security_logs_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.security_logs
    ADD CONSTRAINT security_logs_pkey PRIMARY KEY (id);


--
-- Name: sessions sessions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.sessions
    ADD CONSTRAINT sessions_pkey PRIMARY KEY (id);


--
-- Name: special_sro_rules special_sro_rules_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.special_sro_rules
    ADD CONSTRAINT special_sro_rules_pkey PRIMARY KEY (id);


--
-- Name: subscription_invoices subscription_invoices_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subscription_invoices
    ADD CONSTRAINT subscription_invoices_pkey PRIMARY KEY (id);


--
-- Name: subscription_payments subscription_payments_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subscription_payments
    ADD CONSTRAINT subscription_payments_pkey PRIMARY KEY (id);


--
-- Name: subscriptions subscriptions_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subscriptions
    ADD CONSTRAINT subscriptions_pkey PRIMARY KEY (id);


--
-- Name: suppliers suppliers_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.suppliers
    ADD CONSTRAINT suppliers_pkey PRIMARY KEY (id);


--
-- Name: system_controls system_controls_key_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.system_controls
    ADD CONSTRAINT system_controls_key_unique UNIQUE (key);


--
-- Name: system_controls system_controls_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.system_controls
    ADD CONSTRAINT system_controls_pkey PRIMARY KEY (id);


--
-- Name: system_settings system_settings_key_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.system_settings
    ADD CONSTRAINT system_settings_key_unique UNIQUE (key);


--
-- Name: system_settings system_settings_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.system_settings
    ADD CONSTRAINT system_settings_pkey PRIMARY KEY (id);


--
-- Name: users users_email_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_email_unique UNIQUE (email);


--
-- Name: users users_phone_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_phone_unique UNIQUE (phone);


--
-- Name: users users_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_pkey PRIMARY KEY (id);


--
-- Name: users users_username_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_username_unique UNIQUE (username);


--
-- Name: vendor_risk_profiles vendor_risk_profiles_company_id_vendor_ntn_unique; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.vendor_risk_profiles
    ADD CONSTRAINT vendor_risk_profiles_company_id_vendor_ntn_unique UNIQUE (company_id, vendor_ntn);


--
-- Name: vendor_risk_profiles vendor_risk_profiles_pkey; Type: CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.vendor_risk_profiles
    ADD CONSTRAINT vendor_risk_profiles_pkey PRIMARY KEY (id);


--
-- Name: admin_announcements_is_active_expires_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX admin_announcements_is_active_expires_at_index ON public.admin_announcements USING btree (is_active, expires_at);


--
-- Name: admin_audit_logs_admin_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX admin_audit_logs_admin_id_index ON public.admin_audit_logs USING btree (admin_id);


--
-- Name: admin_audit_logs_target_type_target_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX admin_audit_logs_target_type_target_id_index ON public.admin_audit_logs USING btree (target_type, target_id);


--
-- Name: anomaly_logs_company_id_type_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX anomaly_logs_company_id_type_index ON public.anomaly_logs USING btree (company_id, type);


--
-- Name: anomaly_logs_resolved_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX anomaly_logs_resolved_index ON public.anomaly_logs USING btree (resolved);


--
-- Name: audit_logs_company_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX audit_logs_company_id_index ON public.audit_logs USING btree (company_id);


--
-- Name: audit_logs_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX audit_logs_created_at_index ON public.audit_logs USING btree (created_at);


--
-- Name: cache_expiration_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX cache_expiration_index ON public.cache USING btree (expiration);


--
-- Name: cache_locks_expiration_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX cache_locks_expiration_index ON public.cache_locks USING btree (expiration);


--
-- Name: companies_company_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX companies_company_status_index ON public.companies USING btree (company_status);


--
-- Name: companies_compliance_score_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX companies_compliance_score_index ON public.companies USING btree (compliance_score);


--
-- Name: companies_name_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX companies_name_index ON public.companies USING btree (name);


--
-- Name: compliance_reports_company_id_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX compliance_reports_company_id_created_at_index ON public.compliance_reports USING btree (company_id, created_at);


--
-- Name: compliance_reports_risk_level_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX compliance_reports_risk_level_index ON public.compliance_reports USING btree (risk_level);


--
-- Name: compliance_scores_company_id_calculated_date_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX compliance_scores_company_id_calculated_date_index ON public.compliance_scores USING btree (company_id, calculated_date);


--
-- Name: customer_profiles_company_ntn_not_null; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX customer_profiles_company_ntn_not_null ON public.customer_profiles USING btree (company_id, ntn) WHERE (ntn IS NOT NULL);


--
-- Name: customer_tax_rules_company_id_customer_ntn_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX customer_tax_rules_company_id_customer_ntn_index ON public.customer_tax_rules USING btree (company_id, customer_ntn);


--
-- Name: fbr_day_close_reports_company_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX fbr_day_close_reports_company_id_index ON public.fbr_day_close_reports USING btree (company_id);


--
-- Name: fbr_logs_invoice_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX fbr_logs_invoice_id_status_index ON public.fbr_logs USING btree (invoice_id, status);


--
-- Name: fbr_logs_request_payload_hash_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX fbr_logs_request_payload_hash_index ON public.fbr_logs USING btree (request_payload_hash);


--
-- Name: fbr_logs_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX fbr_logs_status_index ON public.fbr_logs USING btree (status);


--
-- Name: fbr_pos_transactions_fbr_invoice_number_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX fbr_pos_transactions_fbr_invoice_number_index ON public.fbr_pos_transactions USING btree (fbr_invoice_number);


--
-- Name: fbr_pos_transactions_invoice_number_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX fbr_pos_transactions_invoice_number_index ON public.fbr_pos_transactions USING btree (invoice_number);


--
-- Name: global_hs_master_mapping_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX global_hs_master_mapping_status_index ON public.global_hs_master USING btree (mapping_status);


--
-- Name: global_hs_master_schedule_type_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX global_hs_master_schedule_type_index ON public.global_hs_master USING btree (schedule_type);


--
-- Name: global_hs_master_sector_tag_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX global_hs_master_sector_tag_index ON public.global_hs_master USING btree (sector_tag);


--
-- Name: global_hs_master_tax_rate_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX global_hs_master_tax_rate_index ON public.global_hs_master USING btree (tax_rate);


--
-- Name: hs_code_mappings_hs_code_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX hs_code_mappings_hs_code_index ON public.hs_code_mappings USING btree (hs_code);


--
-- Name: hs_code_mappings_hs_code_is_active_priority_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX hs_code_mappings_hs_code_is_active_priority_index ON public.hs_code_mappings USING btree (hs_code, is_active, priority);


--
-- Name: hs_intelligence_logs_confidence_score_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX hs_intelligence_logs_confidence_score_index ON public.hs_intelligence_logs USING btree (confidence_score);


--
-- Name: hs_intelligence_logs_hs_code_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX hs_intelligence_logs_hs_code_index ON public.hs_intelligence_logs USING btree (hs_code);


--
-- Name: hs_mapping_audit_logs_changed_by_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX hs_mapping_audit_logs_changed_by_index ON public.hs_mapping_audit_logs USING btree (changed_by);


--
-- Name: hs_mapping_audit_logs_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX hs_mapping_audit_logs_created_at_index ON public.hs_mapping_audit_logs USING btree (created_at);


--
-- Name: hs_mapping_audit_logs_hs_code_mapping_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX hs_mapping_audit_logs_hs_code_mapping_id_index ON public.hs_mapping_audit_logs USING btree (hs_code_mapping_id);


--
-- Name: hs_mapping_responses_company_id_hs_code_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX hs_mapping_responses_company_id_hs_code_index ON public.hs_mapping_responses USING btree (company_id, hs_code);


--
-- Name: hs_mapping_responses_hs_code_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX hs_mapping_responses_hs_code_index ON public.hs_mapping_responses USING btree (hs_code);


--
-- Name: hs_master_global_default_tax_rate_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX hs_master_global_default_tax_rate_index ON public.hs_master_global USING btree (default_tax_rate);


--
-- Name: hs_master_global_hs_code_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX hs_master_global_hs_code_index ON public.hs_master_global USING btree (hs_code);


--
-- Name: hs_master_global_is_active_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX hs_master_global_is_active_index ON public.hs_master_global USING btree (is_active);


--
-- Name: hs_master_global_schedule_type_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX hs_master_global_schedule_type_index ON public.hs_master_global USING btree (schedule_type);


--
-- Name: hs_rejection_history_hs_code_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX hs_rejection_history_hs_code_index ON public.hs_rejection_history USING btree (hs_code);


--
-- Name: hs_unmapped_log_company_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX hs_unmapped_log_company_id_index ON public.hs_unmapped_log USING btree (company_id);


--
-- Name: hs_unmapped_log_frequency_count_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX hs_unmapped_log_frequency_count_index ON public.hs_unmapped_log USING btree (frequency_count);


--
-- Name: hs_unmapped_log_hs_code_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX hs_unmapped_log_hs_code_index ON public.hs_unmapped_log USING btree (hs_code);


--
-- Name: hs_unmapped_log_last_seen_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX hs_unmapped_log_last_seen_at_index ON public.hs_unmapped_log USING btree (last_seen_at);


--
-- Name: hs_unmapped_queue_hs_code_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX hs_unmapped_queue_hs_code_index ON public.hs_unmapped_queue USING btree (hs_code);


--
-- Name: hs_usage_patterns_hs_code_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX hs_usage_patterns_hs_code_index ON public.hs_usage_patterns USING btree (hs_code);


--
-- Name: idx_ing_company_active; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_ing_company_active ON public.ingredients USING btree (company_id, is_active);


--
-- Name: idx_pr_company_product; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_pr_company_product ON public.product_recipes USING btree (company_id, product_id);


--
-- Name: idx_pt_company_created; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_pt_company_created ON public.pos_transactions USING btree (company_id, created_at);


--
-- Name: idx_pt_company_status; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_pt_company_status ON public.pos_transactions USING btree (company_id, status);


--
-- Name: idx_pti_transaction_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_pti_transaction_id ON public.pos_transaction_items USING btree (transaction_id);


--
-- Name: idx_ro_company_created; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_ro_company_created ON public.restaurant_orders USING btree (company_id, created_at);


--
-- Name: idx_ro_company_status; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_ro_company_status ON public.restaurant_orders USING btree (company_id, status);


--
-- Name: idx_ro_customer_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_ro_customer_id ON public.restaurant_orders USING btree (customer_id);


--
-- Name: idx_ro_table_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_ro_table_id ON public.restaurant_orders USING btree (table_id);


--
-- Name: idx_roi_item; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_roi_item ON public.restaurant_order_items USING btree (item_id, item_type);


--
-- Name: idx_roi_order_id; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_roi_order_id ON public.restaurant_order_items USING btree (order_id);


--
-- Name: idx_rt_company_status; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX idx_rt_company_status ON public.restaurant_tables USING btree (company_id, status);


--
-- Name: ingredients_company_id_is_active_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX ingredients_company_id_is_active_index ON public.ingredients USING btree (company_id, is_active);


--
-- Name: inventory_adjustments_company_id_product_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX inventory_adjustments_company_id_product_id_index ON public.inventory_adjustments USING btree (company_id, product_id);


--
-- Name: inventory_movements_company_id_product_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX inventory_movements_company_id_product_id_index ON public.inventory_movements USING btree (company_id, product_id);


--
-- Name: inventory_movements_company_id_type_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX inventory_movements_company_id_type_index ON public.inventory_movements USING btree (company_id, type);


--
-- Name: inventory_movements_reference_type_reference_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX inventory_movements_reference_type_reference_id_index ON public.inventory_movements USING btree (reference_type, reference_id);


--
-- Name: inventory_stocks_company_id_product_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX inventory_stocks_company_id_product_id_index ON public.inventory_stocks USING btree (company_id, product_id);


--
-- Name: invoice_items_hs_code_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX invoice_items_hs_code_index ON public.invoice_items USING btree (hs_code);


--
-- Name: invoice_items_hs_code_invoice_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX invoice_items_hs_code_invoice_id_index ON public.invoice_items USING btree (hs_code, invoice_id);


--
-- Name: invoice_items_invoice_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX invoice_items_invoice_id_index ON public.invoice_items USING btree (invoice_id);


--
-- Name: invoices_company_id_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX invoices_company_id_created_at_index ON public.invoices USING btree (company_id, created_at);


--
-- Name: invoices_company_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX invoices_company_id_status_index ON public.invoices USING btree (company_id, status);


--
-- Name: invoices_company_status_date_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX invoices_company_status_date_index ON public.invoices USING btree (company_id, status, invoice_date);


--
-- Name: invoices_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX invoices_created_at_index ON public.invoices USING btree (created_at);


--
-- Name: invoices_fbr_submission_hash_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX invoices_fbr_submission_hash_index ON public.invoices USING btree (fbr_submission_hash);


--
-- Name: invoices_invoice_number_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX invoices_invoice_number_index ON public.invoices USING btree (invoice_number);


--
-- Name: invoices_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX invoices_status_index ON public.invoices USING btree (status);


--
-- Name: jobs_queue_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX jobs_queue_index ON public.jobs USING btree (queue);


--
-- Name: override_usage_logs_company_id_override_layer_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX override_usage_logs_company_id_override_layer_index ON public.override_usage_logs USING btree (company_id, override_layer);


--
-- Name: override_usage_logs_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX override_usage_logs_created_at_index ON public.override_usage_logs USING btree (created_at);


--
-- Name: password_reset_otps_email_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX password_reset_otps_email_index ON public.password_reset_otps USING btree (email);


--
-- Name: pos_customers_company_id_is_active_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX pos_customers_company_id_is_active_index ON public.pos_customers USING btree (company_id, is_active);


--
-- Name: pos_day_close_reports_company_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX pos_day_close_reports_company_id_index ON public.pos_day_close_reports USING btree (company_id);


--
-- Name: pos_payments_transaction_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX pos_payments_transaction_id_index ON public.pos_payments USING btree (transaction_id);


--
-- Name: pos_products_company_id_is_active_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX pos_products_company_id_is_active_index ON public.pos_products USING btree (company_id, is_active);


--
-- Name: pos_services_company_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX pos_services_company_id_index ON public.pos_services USING btree (company_id);


--
-- Name: pos_terminals_company_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX pos_terminals_company_id_index ON public.pos_terminals USING btree (company_id);


--
-- Name: pos_transaction_items_transaction_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX pos_transaction_items_transaction_id_index ON public.pos_transaction_items USING btree (transaction_id);


--
-- Name: pos_transactions_company_id_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX pos_transactions_company_id_created_at_index ON public.pos_transactions USING btree (company_id, created_at);


--
-- Name: pos_transactions_company_id_payment_method_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX pos_transactions_company_id_payment_method_index ON public.pos_transactions USING btree (company_id, payment_method);


--
-- Name: pos_transactions_company_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX pos_transactions_company_id_status_index ON public.pos_transactions USING btree (company_id, status);


--
-- Name: pos_transactions_company_invoice_unique; Type: INDEX; Schema: public; Owner: -
--

CREATE UNIQUE INDEX pos_transactions_company_invoice_unique ON public.pos_transactions USING btree (company_id, invoice_number);


--
-- Name: pos_transactions_locked_by_terminal_id_lock_time_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX pos_transactions_locked_by_terminal_id_lock_time_index ON public.pos_transactions USING btree (locked_by_terminal_id, lock_time);


--
-- Name: pos_transactions_submission_hash_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX pos_transactions_submission_hash_index ON public.pos_transactions USING btree (submission_hash);


--
-- Name: pra_logs_company_id_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX pra_logs_company_id_created_at_index ON public.pra_logs USING btree (company_id, created_at);


--
-- Name: product_recipes_company_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX product_recipes_company_id_index ON public.product_recipes USING btree (company_id);


--
-- Name: province_tax_rules_province_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX province_tax_rules_province_index ON public.province_tax_rules USING btree (province);


--
-- Name: purchase_orders_company_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX purchase_orders_company_id_status_index ON public.purchase_orders USING btree (company_id, status);


--
-- Name: restaurant_floors_company_id_is_active_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX restaurant_floors_company_id_is_active_index ON public.restaurant_floors USING btree (company_id, is_active);


--
-- Name: restaurant_order_items_order_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX restaurant_order_items_order_id_index ON public.restaurant_order_items USING btree (order_id);


--
-- Name: restaurant_orders_company_id_created_at_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX restaurant_orders_company_id_created_at_index ON public.restaurant_orders USING btree (company_id, created_at);


--
-- Name: restaurant_orders_company_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX restaurant_orders_company_id_status_index ON public.restaurant_orders USING btree (company_id, status);


--
-- Name: restaurant_orders_company_id_table_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX restaurant_orders_company_id_table_id_index ON public.restaurant_orders USING btree (company_id, table_id);


--
-- Name: restaurant_tables_company_id_floor_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX restaurant_tables_company_id_floor_id_index ON public.restaurant_tables USING btree (company_id, floor_id);


--
-- Name: restaurant_tables_company_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX restaurant_tables_company_id_status_index ON public.restaurant_tables USING btree (company_id, status);


--
-- Name: sector_tax_rules_sector_type_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX sector_tax_rules_sector_type_index ON public.sector_tax_rules USING btree (sector_type);


--
-- Name: sessions_last_activity_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX sessions_last_activity_index ON public.sessions USING btree (last_activity);


--
-- Name: sessions_user_id_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX sessions_user_id_index ON public.sessions USING btree (user_id);


--
-- Name: special_sro_rules_hs_code_schedule_type_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX special_sro_rules_hs_code_schedule_type_index ON public.special_sro_rules USING btree (hs_code, schedule_type);


--
-- Name: subscription_invoices_company_id_status_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX subscription_invoices_company_id_status_index ON public.subscription_invoices USING btree (company_id, status);


--
-- Name: suppliers_company_id_is_active_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX suppliers_company_id_is_active_index ON public.suppliers USING btree (company_id, is_active);


--
-- Name: users_role_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX users_role_index ON public.users USING btree (role);


--
-- Name: vendor_risk_profiles_vendor_score_index; Type: INDEX; Schema: public; Owner: -
--

CREATE INDEX vendor_risk_profiles_vendor_score_index ON public.vendor_risk_profiles USING btree (vendor_score);


--
-- Name: users 1; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT "1" FOREIGN KEY (company_id) REFERENCES public.companies(id) ON DELETE CASCADE;


--
-- Name: invoices 1; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.invoices
    ADD CONSTRAINT "1" FOREIGN KEY (company_id) REFERENCES public.companies(id) ON DELETE CASCADE;


--
-- Name: fbr_logs 1; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fbr_logs
    ADD CONSTRAINT "1" FOREIGN KEY (invoice_id) REFERENCES public.invoices(id) ON DELETE CASCADE;


--
-- Name: admin_announcements admin_announcements_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.admin_announcements
    ADD CONSTRAINT admin_announcements_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: admin_announcements admin_announcements_target_company_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.admin_announcements
    ADD CONSTRAINT admin_announcements_target_company_id_foreign FOREIGN KEY (target_company_id) REFERENCES public.companies(id) ON DELETE CASCADE;


--
-- Name: announcement_dismissals announcement_dismissals_announcement_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.announcement_dismissals
    ADD CONSTRAINT announcement_dismissals_announcement_id_foreign FOREIGN KEY (announcement_id) REFERENCES public.admin_announcements(id) ON DELETE CASCADE;


--
-- Name: announcement_dismissals announcement_dismissals_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.announcement_dismissals
    ADD CONSTRAINT announcement_dismissals_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: anomaly_logs anomaly_logs_company_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.anomaly_logs
    ADD CONSTRAINT anomaly_logs_company_id_foreign FOREIGN KEY (company_id) REFERENCES public.companies(id) ON DELETE CASCADE;


--
-- Name: branches branches_company_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.branches
    ADD CONSTRAINT branches_company_id_foreign FOREIGN KEY (company_id) REFERENCES public.companies(id) ON DELETE CASCADE;


--
-- Name: companies companies_franchise_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.companies
    ADD CONSTRAINT companies_franchise_id_foreign FOREIGN KEY (franchise_id) REFERENCES public.franchises(id) ON DELETE SET NULL;


--
-- Name: company_usage_stats company_usage_stats_company_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.company_usage_stats
    ADD CONSTRAINT company_usage_stats_company_id_foreign FOREIGN KEY (company_id) REFERENCES public.companies(id) ON DELETE CASCADE;


--
-- Name: compliance_reports compliance_reports_company_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.compliance_reports
    ADD CONSTRAINT compliance_reports_company_id_foreign FOREIGN KEY (company_id) REFERENCES public.companies(id) ON DELETE CASCADE;


--
-- Name: compliance_reports compliance_reports_invoice_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.compliance_reports
    ADD CONSTRAINT compliance_reports_invoice_id_foreign FOREIGN KEY (invoice_id) REFERENCES public.invoices(id) ON DELETE SET NULL;


--
-- Name: compliance_scores compliance_scores_company_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.compliance_scores
    ADD CONSTRAINT compliance_scores_company_id_foreign FOREIGN KEY (company_id) REFERENCES public.companies(id) ON DELETE CASCADE;


--
-- Name: customer_ledgers customer_ledgers_company_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customer_ledgers
    ADD CONSTRAINT customer_ledgers_company_id_foreign FOREIGN KEY (company_id) REFERENCES public.companies(id) ON DELETE CASCADE;


--
-- Name: customer_ledgers customer_ledgers_invoice_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customer_ledgers
    ADD CONSTRAINT customer_ledgers_invoice_id_foreign FOREIGN KEY (invoice_id) REFERENCES public.invoices(id) ON DELETE SET NULL;


--
-- Name: customer_profiles customer_profiles_company_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customer_profiles
    ADD CONSTRAINT customer_profiles_company_id_foreign FOREIGN KEY (company_id) REFERENCES public.companies(id) ON DELETE CASCADE;


--
-- Name: customer_tax_rules customer_tax_rules_company_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.customer_tax_rules
    ADD CONSTRAINT customer_tax_rules_company_id_foreign FOREIGN KEY (company_id) REFERENCES public.companies(id) ON DELETE CASCADE;


--
-- Name: fbr_pos_logs fbr_pos_logs_company_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fbr_pos_logs
    ADD CONSTRAINT fbr_pos_logs_company_id_foreign FOREIGN KEY (company_id) REFERENCES public.companies(id) ON DELETE CASCADE;


--
-- Name: fbr_pos_logs fbr_pos_logs_transaction_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fbr_pos_logs
    ADD CONSTRAINT fbr_pos_logs_transaction_id_foreign FOREIGN KEY (transaction_id) REFERENCES public.fbr_pos_transactions(id) ON DELETE SET NULL;


--
-- Name: fbr_pos_transaction_items fbr_pos_transaction_items_transaction_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fbr_pos_transaction_items
    ADD CONSTRAINT fbr_pos_transaction_items_transaction_id_foreign FOREIGN KEY (transaction_id) REFERENCES public.fbr_pos_transactions(id) ON DELETE CASCADE;


--
-- Name: fbr_pos_transactions fbr_pos_transactions_company_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fbr_pos_transactions
    ADD CONSTRAINT fbr_pos_transactions_company_id_foreign FOREIGN KEY (company_id) REFERENCES public.companies(id) ON DELETE CASCADE;


--
-- Name: fbr_pos_transactions fbr_pos_transactions_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.fbr_pos_transactions
    ADD CONSTRAINT fbr_pos_transactions_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: hs_mapping_responses hs_mapping_responses_hs_code_mapping_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hs_mapping_responses
    ADD CONSTRAINT hs_mapping_responses_hs_code_mapping_id_foreign FOREIGN KEY (hs_code_mapping_id) REFERENCES public.hs_code_mappings(id) ON DELETE CASCADE;


--
-- Name: hs_unmapped_queue hs_unmapped_queue_company_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.hs_unmapped_queue
    ADD CONSTRAINT hs_unmapped_queue_company_id_foreign FOREIGN KEY (company_id) REFERENCES public.companies(id) ON DELETE CASCADE;


--
-- Name: ingredients ingredients_company_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.ingredients
    ADD CONSTRAINT ingredients_company_id_foreign FOREIGN KEY (company_id) REFERENCES public.companies(id) ON DELETE CASCADE;


--
-- Name: inventory_adjustments inventory_adjustments_company_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.inventory_adjustments
    ADD CONSTRAINT inventory_adjustments_company_id_foreign FOREIGN KEY (company_id) REFERENCES public.companies(id) ON DELETE CASCADE;


--
-- Name: inventory_adjustments inventory_adjustments_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.inventory_adjustments
    ADD CONSTRAINT inventory_adjustments_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: inventory_adjustments inventory_adjustments_product_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.inventory_adjustments
    ADD CONSTRAINT inventory_adjustments_product_id_foreign FOREIGN KEY (product_id) REFERENCES public.products(id) ON DELETE CASCADE;


--
-- Name: inventory_movements inventory_movements_branch_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.inventory_movements
    ADD CONSTRAINT inventory_movements_branch_id_foreign FOREIGN KEY (branch_id) REFERENCES public.branches(id) ON DELETE SET NULL;


--
-- Name: inventory_movements inventory_movements_company_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.inventory_movements
    ADD CONSTRAINT inventory_movements_company_id_foreign FOREIGN KEY (company_id) REFERENCES public.companies(id) ON DELETE CASCADE;


--
-- Name: inventory_movements inventory_movements_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.inventory_movements
    ADD CONSTRAINT inventory_movements_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: inventory_movements inventory_movements_product_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.inventory_movements
    ADD CONSTRAINT inventory_movements_product_id_foreign FOREIGN KEY (product_id) REFERENCES public.products(id) ON DELETE CASCADE;


--
-- Name: inventory_stocks inventory_stocks_branch_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.inventory_stocks
    ADD CONSTRAINT inventory_stocks_branch_id_foreign FOREIGN KEY (branch_id) REFERENCES public.branches(id) ON DELETE SET NULL;


--
-- Name: inventory_stocks inventory_stocks_company_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.inventory_stocks
    ADD CONSTRAINT inventory_stocks_company_id_foreign FOREIGN KEY (company_id) REFERENCES public.companies(id) ON DELETE CASCADE;


--
-- Name: invoice_activity_logs invoice_activity_logs_company_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.invoice_activity_logs
    ADD CONSTRAINT invoice_activity_logs_company_id_foreign FOREIGN KEY (company_id) REFERENCES public.companies(id) ON DELETE CASCADE;


--
-- Name: invoice_activity_logs invoice_activity_logs_invoice_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.invoice_activity_logs
    ADD CONSTRAINT invoice_activity_logs_invoice_id_foreign FOREIGN KEY (invoice_id) REFERENCES public.invoices(id) ON DELETE CASCADE;


--
-- Name: invoice_activity_logs invoice_activity_logs_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.invoice_activity_logs
    ADD CONSTRAINT invoice_activity_logs_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: invoice_items invoice_items_invoice_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.invoice_items
    ADD CONSTRAINT invoice_items_invoice_id_foreign FOREIGN KEY (invoice_id) REFERENCES public.invoices(id) ON DELETE CASCADE;


--
-- Name: invoices invoices_branch_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.invoices
    ADD CONSTRAINT invoices_branch_id_foreign FOREIGN KEY (branch_id) REFERENCES public.branches(id) ON DELETE SET NULL;


--
-- Name: invoices invoices_override_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.invoices
    ADD CONSTRAINT invoices_override_by_foreign FOREIGN KEY (override_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: notifications notifications_company_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notifications
    ADD CONSTRAINT notifications_company_id_foreign FOREIGN KEY (company_id) REFERENCES public.companies(id) ON DELETE CASCADE;


--
-- Name: notifications notifications_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.notifications
    ADD CONSTRAINT notifications_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: override_logs override_logs_company_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.override_logs
    ADD CONSTRAINT override_logs_company_id_foreign FOREIGN KEY (company_id) REFERENCES public.companies(id);


--
-- Name: override_logs override_logs_invoice_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.override_logs
    ADD CONSTRAINT override_logs_invoice_id_foreign FOREIGN KEY (invoice_id) REFERENCES public.invoices(id);


--
-- Name: override_logs override_logs_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.override_logs
    ADD CONSTRAINT override_logs_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id);


--
-- Name: override_usage_logs override_usage_logs_company_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.override_usage_logs
    ADD CONSTRAINT override_usage_logs_company_id_foreign FOREIGN KEY (company_id) REFERENCES public.companies(id) ON DELETE CASCADE;


--
-- Name: override_usage_logs override_usage_logs_invoice_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.override_usage_logs
    ADD CONSTRAINT override_usage_logs_invoice_id_foreign FOREIGN KEY (invoice_id) REFERENCES public.invoices(id) ON DELETE SET NULL;


--
-- Name: pos_customers pos_customers_company_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pos_customers
    ADD CONSTRAINT pos_customers_company_id_foreign FOREIGN KEY (company_id) REFERENCES public.companies(id) ON DELETE CASCADE;


--
-- Name: pos_payments pos_payments_transaction_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pos_payments
    ADD CONSTRAINT pos_payments_transaction_id_foreign FOREIGN KEY (transaction_id) REFERENCES public.pos_transactions(id) ON DELETE CASCADE;


--
-- Name: pos_products pos_products_company_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pos_products
    ADD CONSTRAINT pos_products_company_id_foreign FOREIGN KEY (company_id) REFERENCES public.companies(id) ON DELETE CASCADE;


--
-- Name: pos_services pos_services_company_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pos_services
    ADD CONSTRAINT pos_services_company_id_foreign FOREIGN KEY (company_id) REFERENCES public.companies(id) ON DELETE CASCADE;


--
-- Name: pos_terminals pos_terminals_company_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pos_terminals
    ADD CONSTRAINT pos_terminals_company_id_foreign FOREIGN KEY (company_id) REFERENCES public.companies(id) ON DELETE CASCADE;


--
-- Name: pos_transaction_items pos_transaction_items_transaction_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pos_transaction_items
    ADD CONSTRAINT pos_transaction_items_transaction_id_foreign FOREIGN KEY (transaction_id) REFERENCES public.pos_transactions(id) ON DELETE CASCADE;


--
-- Name: pos_transactions pos_transactions_company_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pos_transactions
    ADD CONSTRAINT pos_transactions_company_id_foreign FOREIGN KEY (company_id) REFERENCES public.companies(id) ON DELETE CASCADE;


--
-- Name: pos_transactions pos_transactions_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pos_transactions
    ADD CONSTRAINT pos_transactions_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: pos_transactions pos_transactions_locked_by_terminal_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pos_transactions
    ADD CONSTRAINT pos_transactions_locked_by_terminal_id_foreign FOREIGN KEY (locked_by_terminal_id) REFERENCES public.pos_terminals(id) ON DELETE SET NULL;


--
-- Name: pos_transactions pos_transactions_terminal_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pos_transactions
    ADD CONSTRAINT pos_transactions_terminal_id_foreign FOREIGN KEY (terminal_id) REFERENCES public.pos_terminals(id) ON DELETE SET NULL;


--
-- Name: pra_logs pra_logs_company_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pra_logs
    ADD CONSTRAINT pra_logs_company_id_foreign FOREIGN KEY (company_id) REFERENCES public.companies(id) ON DELETE CASCADE;


--
-- Name: pra_logs pra_logs_transaction_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.pra_logs
    ADD CONSTRAINT pra_logs_transaction_id_foreign FOREIGN KEY (transaction_id) REFERENCES public.pos_transactions(id) ON DELETE SET NULL;


--
-- Name: product_recipes product_recipes_company_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.product_recipes
    ADD CONSTRAINT product_recipes_company_id_foreign FOREIGN KEY (company_id) REFERENCES public.companies(id) ON DELETE CASCADE;


--
-- Name: product_recipes product_recipes_ingredient_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.product_recipes
    ADD CONSTRAINT product_recipes_ingredient_id_foreign FOREIGN KEY (ingredient_id) REFERENCES public.ingredients(id) ON DELETE CASCADE;


--
-- Name: product_recipes product_recipes_product_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.product_recipes
    ADD CONSTRAINT product_recipes_product_id_foreign FOREIGN KEY (product_id) REFERENCES public.pos_products(id) ON DELETE CASCADE;


--
-- Name: products products_company_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.products
    ADD CONSTRAINT products_company_id_foreign FOREIGN KEY (company_id) REFERENCES public.companies(id) ON DELETE CASCADE;


--
-- Name: purchase_order_items purchase_order_items_product_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.purchase_order_items
    ADD CONSTRAINT purchase_order_items_product_id_foreign FOREIGN KEY (product_id) REFERENCES public.products(id) ON DELETE CASCADE;


--
-- Name: purchase_order_items purchase_order_items_purchase_order_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.purchase_order_items
    ADD CONSTRAINT purchase_order_items_purchase_order_id_foreign FOREIGN KEY (purchase_order_id) REFERENCES public.purchase_orders(id) ON DELETE CASCADE;


--
-- Name: purchase_orders purchase_orders_branch_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.purchase_orders
    ADD CONSTRAINT purchase_orders_branch_id_foreign FOREIGN KEY (branch_id) REFERENCES public.branches(id) ON DELETE SET NULL;


--
-- Name: purchase_orders purchase_orders_company_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.purchase_orders
    ADD CONSTRAINT purchase_orders_company_id_foreign FOREIGN KEY (company_id) REFERENCES public.companies(id) ON DELETE CASCADE;


--
-- Name: purchase_orders purchase_orders_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.purchase_orders
    ADD CONSTRAINT purchase_orders_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: purchase_orders purchase_orders_supplier_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.purchase_orders
    ADD CONSTRAINT purchase_orders_supplier_id_foreign FOREIGN KEY (supplier_id) REFERENCES public.suppliers(id) ON DELETE SET NULL;


--
-- Name: restaurant_floors restaurant_floors_company_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.restaurant_floors
    ADD CONSTRAINT restaurant_floors_company_id_foreign FOREIGN KEY (company_id) REFERENCES public.companies(id) ON DELETE CASCADE;


--
-- Name: restaurant_order_items restaurant_order_items_order_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.restaurant_order_items
    ADD CONSTRAINT restaurant_order_items_order_id_foreign FOREIGN KEY (order_id) REFERENCES public.restaurant_orders(id) ON DELETE CASCADE;


--
-- Name: restaurant_orders restaurant_orders_company_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.restaurant_orders
    ADD CONSTRAINT restaurant_orders_company_id_foreign FOREIGN KEY (company_id) REFERENCES public.companies(id) ON DELETE CASCADE;


--
-- Name: restaurant_orders restaurant_orders_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.restaurant_orders
    ADD CONSTRAINT restaurant_orders_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: restaurant_orders restaurant_orders_customer_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.restaurant_orders
    ADD CONSTRAINT restaurant_orders_customer_id_foreign FOREIGN KEY (customer_id) REFERENCES public.pos_customers(id) ON DELETE SET NULL;


--
-- Name: restaurant_orders restaurant_orders_pos_transaction_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.restaurant_orders
    ADD CONSTRAINT restaurant_orders_pos_transaction_id_foreign FOREIGN KEY (pos_transaction_id) REFERENCES public.pos_transactions(id) ON DELETE SET NULL;


--
-- Name: restaurant_orders restaurant_orders_table_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.restaurant_orders
    ADD CONSTRAINT restaurant_orders_table_id_foreign FOREIGN KEY (table_id) REFERENCES public.restaurant_tables(id) ON DELETE SET NULL;


--
-- Name: restaurant_tables restaurant_tables_company_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.restaurant_tables
    ADD CONSTRAINT restaurant_tables_company_id_foreign FOREIGN KEY (company_id) REFERENCES public.companies(id) ON DELETE CASCADE;


--
-- Name: restaurant_tables restaurant_tables_floor_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.restaurant_tables
    ADD CONSTRAINT restaurant_tables_floor_id_foreign FOREIGN KEY (floor_id) REFERENCES public.restaurant_floors(id) ON DELETE CASCADE;


--
-- Name: restaurant_tables restaurant_tables_locked_by_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.restaurant_tables
    ADD CONSTRAINT restaurant_tables_locked_by_user_id_foreign FOREIGN KEY (locked_by_user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: security_logs security_logs_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.security_logs
    ADD CONSTRAINT security_logs_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: subscription_invoices subscription_invoices_company_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subscription_invoices
    ADD CONSTRAINT subscription_invoices_company_id_foreign FOREIGN KEY (company_id) REFERENCES public.companies(id) ON DELETE CASCADE;


--
-- Name: subscription_invoices subscription_invoices_subscription_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subscription_invoices
    ADD CONSTRAINT subscription_invoices_subscription_id_foreign FOREIGN KEY (subscription_id) REFERENCES public.subscriptions(id) ON DELETE CASCADE;


--
-- Name: subscription_payments subscription_payments_subscription_invoice_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subscription_payments
    ADD CONSTRAINT subscription_payments_subscription_invoice_id_foreign FOREIGN KEY (subscription_invoice_id) REFERENCES public.subscription_invoices(id) ON DELETE CASCADE;


--
-- Name: subscriptions subscriptions_company_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subscriptions
    ADD CONSTRAINT subscriptions_company_id_foreign FOREIGN KEY (company_id) REFERENCES public.companies(id) ON DELETE CASCADE;


--
-- Name: subscriptions subscriptions_pricing_plan_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.subscriptions
    ADD CONSTRAINT subscriptions_pricing_plan_id_foreign FOREIGN KEY (pricing_plan_id) REFERENCES public.pricing_plans(id) ON DELETE CASCADE;


--
-- Name: suppliers suppliers_company_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.suppliers
    ADD CONSTRAINT suppliers_company_id_foreign FOREIGN KEY (company_id) REFERENCES public.companies(id) ON DELETE CASCADE;


--
-- Name: vendor_risk_profiles vendor_risk_profiles_company_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: -
--

ALTER TABLE ONLY public.vendor_risk_profiles
    ADD CONSTRAINT vendor_risk_profiles_company_id_foreign FOREIGN KEY (company_id) REFERENCES public.companies(id) ON DELETE CASCADE;


--
-- PostgreSQL database dump complete
--

\unrestrict nJcBMh9ZfrmdnfOiHZq8XpVSndaNJLaJHXNwwqT0w2iiy0Kiu0xgDzoUhsQzJfi

--
-- PostgreSQL database dump
--

\restrict 97LftBKVxImTUijHuNiki95fnFjkHgsPhM1P0QQ79nWyeypIOAN71pXE4MlHzRG

-- Dumped from database version 16.10
-- Dumped by pg_dump version 16.10

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

--
-- Data for Name: migrations; Type: TABLE DATA; Schema: public; Owner: -
--

COPY public.migrations (id, migration, batch) FROM stdin;
1	0000_01_01_000000_create_companies_table	1
2	0001_01_01_000000_create_users_table	1
3	0001_01_01_000001_create_cache_table	1
4	0001_01_01_000002_create_jobs_table	1
5	2026_02_11_075345_create_invoices_table	1
6	2026_02_11_075346_create_invoice_items_table	1
7	2026_02_11_080636_create_fbr_logs_table	1
8	2026_02_11_081004_add_role_to_users_table	1
9	2026_02_11_081526_create_pricing_plans_table	1
10	2026_02_11_081526_create_subscriptions_table	1
11	2026_02_11_101444_add_integrity_hash_to_invoices	1
12	2026_02_11_101445_create_invoice_activity_logs_table	1
13	2026_02_11_101446_add_fields_to_fbr_logs	1
14	2026_02_11_101447_add_compliance_score_to_companies	1
15	2026_02_11_101448_create_security_logs_table	1
16	2026_02_12_000001_add_token_expires_at_to_companies	2
17	2026_02_12_000002_create_notifications_table	2
18	2026_02_12_000003_create_compliance_scores_table	2
19	2026_02_12_000004_create_anomaly_logs_table	2
20	2026_02_12_000005_add_trial_ends_at_to_subscriptions	2
21	2026_02_12_100001_create_compliance_reports_table	3
22	2026_02_12_100002_create_vendor_risk_profiles_table	3
23	2026_02_12_200001_create_products_table	4
24	2026_02_12_200002_create_system_settings_table	4
25	2026_02_12_200003_add_override_and_qr_to_invoices	4
26	2026_02_12_200004_create_override_logs_table	5
27	2026_02_12_300001_add_share_uuid_to_invoices	6
28	2026_02_11_121849_add_schedule_fields_to_invoice_items_table	7
29	2026_02_12_400001_add_governance_fields	8
30	2026_02_12_500001_add_enterprise_heavy_fields	9
31	2026_02_11_133730_add_pricing_plan_limits_and_billing_cycle	10
32	2026_02_11_133750_add_pricing_limits_and_billing_cycle	10
33	2026_02_12_000001_add_internal_account_and_onboarding	11
34	2026_02_12_100001_add_dual_invoice_numbers	12
35	2026_02_12_600001_add_multi_layer_tax_intelligence	13
36	2026_02_11_155459_add_uom_and_compliance_fields_to_invoice_items_table	14
37	2026_02_12_700001_fbr_pral_alignment_upgrade	15
38	2026_02_12_800001_enterprise_intelligence_layer	16
39	2026_02_12_900001_create_global_hs_intelligence	17
40	2026_02_12_022826_add_applicability_flags_to_global_hs_master	18
41	2026_02_12_034341_create_hs_master_global_table	19
42	2026_02_12_034342_create_hs_unmapped_queue_table	19
43	2026_02_12_044217_create_hs_intelligence_logs_table	20
44	2026_02_12_044222_create_hs_rejection_history_table	20
45	2026_02_12_045732_add_fbr_rejection_fields_to_hs_rejection_history	21
46	2026_02_12_100000_add_buyer_cnic_address_to_invoices	22
47	2026_02_12_061545_add_fbr_urls_to_companies	23
48	2026_02_12_070000_create_customer_profiles_table	24
49	2026_02_12_063330_add_phone_and_username_to_users_table	25
50	2026_02_12_091036_add_province_to_customer_profiles_table	26
51	2026_02_13_050955_add_cnic_and_business_activity_to_companies_table	27
52	2026_02_13_052940_add_limit_overrides_to_companies_table	28
53	2026_02_13_100001_production_safety_hardening	29
54	2026_02_13_113626_create_hs_usage_patterns_table	30
55	2026_02_13_134414_add_production_stability_phase_a	31
56	2026_02_13_135933_add_composite_index_invoice_items	32
57	2026_02_14_060613_add_profile_fields_to_companies_table	33
58	2026_02_14_091147_add_serial_number_and_mrp_to_products_table	34
59	2026_02_15_154349_add_fbr_idempotency_shield	35
60	2026_02_15_174833_enterprise_ux_simplification_remove_submitted_status	36
61	2026_02_15_180700_enterprise_idempotency_scoped_implementation	37
62	2026_02_16_064335_add_is_fbr_validated_to_compliance_reports	38
63	2026_02_16_080718_add_wht_locked_to_invoices	39
64	2026_02_17_120000_create_hs_code_mappings_tables	40
65	2026_02_17_142222_create_hs_mapping_audit_logs_table	41
66	2026_02_17_150000_create_inventory_module	42
67	2026_02_17_160000_create_admin_announcements_table	43
68	2026_02_18_000001_add_performance_indexes	44
69	2026_03_05_000001_create_nestpos_module	45
70	2026_03_05_000002_add_pra_fields_to_companies	46
71	2026_03_05_174212_add_submission_hash_to_pos_transactions	47
72	2026_03_05_180739_add_pra_qr_and_receipt_settings	48
73	2026_03_05_200001_add_pos_draft_and_lock_fields	49
74	2026_03_06_000001_create_inventory_adjustments_table	50
75	2026_03_06_100001_create_saas_layer_tables	51
76	2026_03_06_200001_create_pos_products_and_customers	52
77	2026_03_06_160258_add_logo_to_companies_table	53
78	2026_03_07_175512_add_product_type_to_pricing_plans_table	54
79	2026_03_19_102849_add_tax_exempt_to_pos_tables	54
80	2026_03_19_000001_add_share_token_to_pos_transactions	55
81	2026_03_24_080259_add_dual_mode_and_cashier_support	55
82	2026_03_25_000001_add_missing_pra_access_code	56
83	2026_03_25_000002_add_all_missing_columns	56
84	2026_03_25_000003_create_password_reset_otps_table	56
85	2026_03_25_000004_add_token_to_password_reset_otps	57
86	2026_03_25_000005_add_product_type_and_soft_delete_to_companies	58
87	2026_03_25_144818_add_pra_proxy_url_to_companies_table	59
88	2026_03_26_200000_add_force_watermark_to_companies_table	60
89	2026_03_26_210000_seed_rao_brothers_company	61
90	2026_03_26_220000_fix_rao_brothers_status	62
91	2026_03_27_000001_create_fbr_pos_module	63
124	2026_03_27_100000_add_fbr_reporting_toggle	64
125	2026_03_27_200000_add_fbrpos_pricing_plans	65
126	2026_03_28_100000_add_uom_to_fbr_pos_items	66
127	2026_03_28_110000_add_fbr_service_charge	67
128	2026_03_28_150326_create_fbr_day_close_reports_table	68
129	2026_03_28_151106_create_pos_day_close_reports_table	69
131	2026_03_28_193257_create_restaurant_floors_table	70
132	2026_03_28_193258_create_restaurant_tables_table	70
133	2026_03_28_193259_create_restaurant_orders_table	70
134	2026_03_28_193260_create_restaurant_order_items_table	70
135	2026_03_28_193300_create_ingredients_table	70
136	2026_03_28_193300_create_product_recipes_table	70
137	2026_03_28_200001_add_restaurant_enterprise_fields	71
138	2026_03_28_200002_add_restaurant_mode_to_companies	72
139	2026_03_29_165551_add_pos_type_to_companies_table	73
140	2026_03_29_182258_add_discount_fields_to_restaurant_orders	74
141	2026_03_29_184659_add_priority_to_restaurant_orders	75
142	2026_03_29_190535_add_item_discount_to_restaurant_order_items	76
143	2026_03_29_190743_add_item_discount_to_pos_transaction_items	77
144	2026_03_29_192534_add_restaurant_polish_columns	78
145	2026_03_29_200001_add_production_indexes_restaurant	79
\.


--
-- Name: migrations_id_seq; Type: SEQUENCE SET; Schema: public; Owner: -
--

SELECT pg_catalog.setval('public.migrations_id_seq', 145, true);


--
-- PostgreSQL database dump complete
--

\unrestrict 97LftBKVxImTUijHuNiki95fnFjkHgsPhM1P0QQ79nWyeypIOAN71pXE4MlHzRG

