--
-- PostgreSQL database dump
--

\restrict D3XMBkUH5sdM7T4cDCcCb0Qs9xXlVEuEXhYhGsTZY8OE98FrXrkhmgcKy7Q2Nj5

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
-- Name: admin_announcements; Type: TABLE; Schema: public; Owner: postgres
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


ALTER TABLE public.admin_announcements OWNER TO postgres;

--
-- Name: admin_announcements_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.admin_announcements_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.admin_announcements_id_seq OWNER TO postgres;

--
-- Name: admin_announcements_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.admin_announcements_id_seq OWNED BY public.admin_announcements.id;


--
-- Name: admin_audit_logs; Type: TABLE; Schema: public; Owner: postgres
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


ALTER TABLE public.admin_audit_logs OWNER TO postgres;

--
-- Name: admin_audit_logs_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.admin_audit_logs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.admin_audit_logs_id_seq OWNER TO postgres;

--
-- Name: admin_audit_logs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.admin_audit_logs_id_seq OWNED BY public.admin_audit_logs.id;


--
-- Name: admin_users; Type: TABLE; Schema: public; Owner: postgres
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


ALTER TABLE public.admin_users OWNER TO postgres;

--
-- Name: admin_users_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.admin_users_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.admin_users_id_seq OWNER TO postgres;

--
-- Name: admin_users_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.admin_users_id_seq OWNED BY public.admin_users.id;


--
-- Name: announcement_dismissals; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.announcement_dismissals (
    id bigint NOT NULL,
    announcement_id bigint NOT NULL,
    user_id bigint NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.announcement_dismissals OWNER TO postgres;

--
-- Name: announcement_dismissals_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.announcement_dismissals_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.announcement_dismissals_id_seq OWNER TO postgres;

--
-- Name: announcement_dismissals_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.announcement_dismissals_id_seq OWNED BY public.announcement_dismissals.id;


--
-- Name: anomaly_logs; Type: TABLE; Schema: public; Owner: postgres
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


ALTER TABLE public.anomaly_logs OWNER TO postgres;

--
-- Name: anomaly_logs_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.anomaly_logs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.anomaly_logs_id_seq OWNER TO postgres;

--
-- Name: anomaly_logs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.anomaly_logs_id_seq OWNED BY public.anomaly_logs.id;


--
-- Name: audit_logs; Type: TABLE; Schema: public; Owner: postgres
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


ALTER TABLE public.audit_logs OWNER TO postgres;

--
-- Name: audit_logs_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.audit_logs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.audit_logs_id_seq OWNER TO postgres;

--
-- Name: audit_logs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.audit_logs_id_seq OWNED BY public.audit_logs.id;


--
-- Name: branches; Type: TABLE; Schema: public; Owner: postgres
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


ALTER TABLE public.branches OWNER TO postgres;

--
-- Name: branches_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.branches_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.branches_id_seq OWNER TO postgres;

--
-- Name: branches_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.branches_id_seq OWNED BY public.branches.id;


--
-- Name: cache; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.cache (
    key character varying(255) NOT NULL,
    value text NOT NULL,
    expiration integer NOT NULL
);


ALTER TABLE public.cache OWNER TO postgres;

--
-- Name: cache_locks; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.cache_locks (
    key character varying(255) NOT NULL,
    owner character varying(255) NOT NULL,
    expiration integer NOT NULL
);


ALTER TABLE public.cache_locks OWNER TO postgres;

--
-- Name: companies; Type: TABLE; Schema: public; Owner: postgres
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
    next_local_invoice_number integer DEFAULT 1 NOT NULL
);


ALTER TABLE public.companies OWNER TO postgres;

--
-- Name: companies_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.companies_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.companies_id_seq OWNER TO postgres;

--
-- Name: companies_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.companies_id_seq OWNED BY public.companies.id;


--
-- Name: company_usage_stats; Type: TABLE; Schema: public; Owner: postgres
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


ALTER TABLE public.company_usage_stats OWNER TO postgres;

--
-- Name: company_usage_stats_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.company_usage_stats_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.company_usage_stats_id_seq OWNER TO postgres;

--
-- Name: company_usage_stats_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.company_usage_stats_id_seq OWNED BY public.company_usage_stats.id;


--
-- Name: compliance_reports; Type: TABLE; Schema: public; Owner: postgres
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


ALTER TABLE public.compliance_reports OWNER TO postgres;

--
-- Name: compliance_reports_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.compliance_reports_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.compliance_reports_id_seq OWNER TO postgres;

--
-- Name: compliance_reports_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.compliance_reports_id_seq OWNED BY public.compliance_reports.id;


--
-- Name: compliance_scores; Type: TABLE; Schema: public; Owner: postgres
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


ALTER TABLE public.compliance_scores OWNER TO postgres;

--
-- Name: compliance_scores_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.compliance_scores_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.compliance_scores_id_seq OWNER TO postgres;

--
-- Name: compliance_scores_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.compliance_scores_id_seq OWNED BY public.compliance_scores.id;


--
-- Name: customer_ledgers; Type: TABLE; Schema: public; Owner: postgres
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


ALTER TABLE public.customer_ledgers OWNER TO postgres;

--
-- Name: customer_ledgers_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.customer_ledgers_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.customer_ledgers_id_seq OWNER TO postgres;

--
-- Name: customer_ledgers_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.customer_ledgers_id_seq OWNED BY public.customer_ledgers.id;


--
-- Name: customer_profiles; Type: TABLE; Schema: public; Owner: postgres
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


ALTER TABLE public.customer_profiles OWNER TO postgres;

--
-- Name: customer_profiles_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.customer_profiles_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.customer_profiles_id_seq OWNER TO postgres;

--
-- Name: customer_profiles_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.customer_profiles_id_seq OWNED BY public.customer_profiles.id;


--
-- Name: customer_tax_rules; Type: TABLE; Schema: public; Owner: postgres
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


ALTER TABLE public.customer_tax_rules OWNER TO postgres;

--
-- Name: customer_tax_rules_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.customer_tax_rules_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.customer_tax_rules_id_seq OWNER TO postgres;

--
-- Name: customer_tax_rules_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.customer_tax_rules_id_seq OWNED BY public.customer_tax_rules.id;


--
-- Name: failed_jobs; Type: TABLE; Schema: public; Owner: postgres
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


ALTER TABLE public.failed_jobs OWNER TO postgres;

--
-- Name: failed_jobs_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.failed_jobs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.failed_jobs_id_seq OWNER TO postgres;

--
-- Name: failed_jobs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.failed_jobs_id_seq OWNED BY public.failed_jobs.id;


--
-- Name: fbr_logs; Type: TABLE; Schema: public; Owner: postgres
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


ALTER TABLE public.fbr_logs OWNER TO postgres;

--
-- Name: fbr_logs_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.fbr_logs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.fbr_logs_id_seq OWNER TO postgres;

--
-- Name: fbr_logs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.fbr_logs_id_seq OWNED BY public.fbr_logs.id;


--
-- Name: franchises; Type: TABLE; Schema: public; Owner: postgres
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


ALTER TABLE public.franchises OWNER TO postgres;

--
-- Name: franchises_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.franchises_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.franchises_id_seq OWNER TO postgres;

--
-- Name: franchises_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.franchises_id_seq OWNED BY public.franchises.id;


--
-- Name: global_hs_master; Type: TABLE; Schema: public; Owner: postgres
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


ALTER TABLE public.global_hs_master OWNER TO postgres;

--
-- Name: global_hs_master_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.global_hs_master_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.global_hs_master_id_seq OWNER TO postgres;

--
-- Name: global_hs_master_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.global_hs_master_id_seq OWNED BY public.global_hs_master.id;


--
-- Name: hs_code_mappings; Type: TABLE; Schema: public; Owner: postgres
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


ALTER TABLE public.hs_code_mappings OWNER TO postgres;

--
-- Name: hs_code_mappings_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.hs_code_mappings_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.hs_code_mappings_id_seq OWNER TO postgres;

--
-- Name: hs_code_mappings_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.hs_code_mappings_id_seq OWNED BY public.hs_code_mappings.id;


--
-- Name: hs_intelligence_logs; Type: TABLE; Schema: public; Owner: postgres
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


ALTER TABLE public.hs_intelligence_logs OWNER TO postgres;

--
-- Name: hs_intelligence_logs_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.hs_intelligence_logs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.hs_intelligence_logs_id_seq OWNER TO postgres;

--
-- Name: hs_intelligence_logs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.hs_intelligence_logs_id_seq OWNED BY public.hs_intelligence_logs.id;


--
-- Name: hs_mapping_audit_logs; Type: TABLE; Schema: public; Owner: postgres
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


ALTER TABLE public.hs_mapping_audit_logs OWNER TO postgres;

--
-- Name: hs_mapping_audit_logs_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.hs_mapping_audit_logs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.hs_mapping_audit_logs_id_seq OWNER TO postgres;

--
-- Name: hs_mapping_audit_logs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.hs_mapping_audit_logs_id_seq OWNED BY public.hs_mapping_audit_logs.id;


--
-- Name: hs_mapping_responses; Type: TABLE; Schema: public; Owner: postgres
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


ALTER TABLE public.hs_mapping_responses OWNER TO postgres;

--
-- Name: hs_mapping_responses_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.hs_mapping_responses_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.hs_mapping_responses_id_seq OWNER TO postgres;

--
-- Name: hs_mapping_responses_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.hs_mapping_responses_id_seq OWNED BY public.hs_mapping_responses.id;


--
-- Name: hs_master_global; Type: TABLE; Schema: public; Owner: postgres
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


ALTER TABLE public.hs_master_global OWNER TO postgres;

--
-- Name: hs_master_global_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.hs_master_global_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.hs_master_global_id_seq OWNER TO postgres;

--
-- Name: hs_master_global_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.hs_master_global_id_seq OWNED BY public.hs_master_global.id;


--
-- Name: hs_rejection_history; Type: TABLE; Schema: public; Owner: postgres
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


ALTER TABLE public.hs_rejection_history OWNER TO postgres;

--
-- Name: hs_rejection_history_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.hs_rejection_history_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.hs_rejection_history_id_seq OWNER TO postgres;

--
-- Name: hs_rejection_history_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.hs_rejection_history_id_seq OWNED BY public.hs_rejection_history.id;


--
-- Name: hs_unmapped_log; Type: TABLE; Schema: public; Owner: postgres
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


ALTER TABLE public.hs_unmapped_log OWNER TO postgres;

--
-- Name: hs_unmapped_log_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.hs_unmapped_log_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.hs_unmapped_log_id_seq OWNER TO postgres;

--
-- Name: hs_unmapped_log_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.hs_unmapped_log_id_seq OWNED BY public.hs_unmapped_log.id;


--
-- Name: hs_unmapped_queue; Type: TABLE; Schema: public; Owner: postgres
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


ALTER TABLE public.hs_unmapped_queue OWNER TO postgres;

--
-- Name: hs_unmapped_queue_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.hs_unmapped_queue_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.hs_unmapped_queue_id_seq OWNER TO postgres;

--
-- Name: hs_unmapped_queue_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.hs_unmapped_queue_id_seq OWNED BY public.hs_unmapped_queue.id;


--
-- Name: hs_usage_patterns; Type: TABLE; Schema: public; Owner: postgres
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


ALTER TABLE public.hs_usage_patterns OWNER TO postgres;

--
-- Name: hs_usage_patterns_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.hs_usage_patterns_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.hs_usage_patterns_id_seq OWNER TO postgres;

--
-- Name: hs_usage_patterns_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.hs_usage_patterns_id_seq OWNED BY public.hs_usage_patterns.id;


--
-- Name: inventory_adjustments; Type: TABLE; Schema: public; Owner: postgres
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


ALTER TABLE public.inventory_adjustments OWNER TO postgres;

--
-- Name: inventory_adjustments_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.inventory_adjustments_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.inventory_adjustments_id_seq OWNER TO postgres;

--
-- Name: inventory_adjustments_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.inventory_adjustments_id_seq OWNED BY public.inventory_adjustments.id;


--
-- Name: inventory_movements; Type: TABLE; Schema: public; Owner: postgres
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


ALTER TABLE public.inventory_movements OWNER TO postgres;

--
-- Name: inventory_movements_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.inventory_movements_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.inventory_movements_id_seq OWNER TO postgres;

--
-- Name: inventory_movements_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.inventory_movements_id_seq OWNED BY public.inventory_movements.id;


--
-- Name: inventory_stocks; Type: TABLE; Schema: public; Owner: postgres
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


ALTER TABLE public.inventory_stocks OWNER TO postgres;

--
-- Name: inventory_stocks_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.inventory_stocks_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.inventory_stocks_id_seq OWNER TO postgres;

--
-- Name: inventory_stocks_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.inventory_stocks_id_seq OWNED BY public.inventory_stocks.id;


--
-- Name: invoice_activity_logs; Type: TABLE; Schema: public; Owner: postgres
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


ALTER TABLE public.invoice_activity_logs OWNER TO postgres;

--
-- Name: invoice_activity_logs_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.invoice_activity_logs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.invoice_activity_logs_id_seq OWNER TO postgres;

--
-- Name: invoice_activity_logs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.invoice_activity_logs_id_seq OWNED BY public.invoice_activity_logs.id;


--
-- Name: invoice_items; Type: TABLE; Schema: public; Owner: postgres
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


ALTER TABLE public.invoice_items OWNER TO postgres;

--
-- Name: invoice_items_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.invoice_items_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.invoice_items_id_seq OWNER TO postgres;

--
-- Name: invoice_items_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.invoice_items_id_seq OWNED BY public.invoice_items.id;


--
-- Name: invoices; Type: TABLE; Schema: public; Owner: postgres
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


ALTER TABLE public.invoices OWNER TO postgres;

--
-- Name: invoices_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.invoices_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.invoices_id_seq OWNER TO postgres;

--
-- Name: invoices_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.invoices_id_seq OWNED BY public.invoices.id;


--
-- Name: job_batches; Type: TABLE; Schema: public; Owner: postgres
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


ALTER TABLE public.job_batches OWNER TO postgres;

--
-- Name: jobs; Type: TABLE; Schema: public; Owner: postgres
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


ALTER TABLE public.jobs OWNER TO postgres;

--
-- Name: jobs_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.jobs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.jobs_id_seq OWNER TO postgres;

--
-- Name: jobs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.jobs_id_seq OWNED BY public.jobs.id;


--
-- Name: migrations; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.migrations (
    id integer NOT NULL,
    migration character varying(255) NOT NULL,
    batch integer NOT NULL
);


ALTER TABLE public.migrations OWNER TO postgres;

--
-- Name: migrations_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.migrations_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.migrations_id_seq OWNER TO postgres;

--
-- Name: migrations_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.migrations_id_seq OWNED BY public.migrations.id;


--
-- Name: notifications; Type: TABLE; Schema: public; Owner: postgres
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


ALTER TABLE public.notifications OWNER TO postgres;

--
-- Name: notifications_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.notifications_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.notifications_id_seq OWNER TO postgres;

--
-- Name: notifications_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.notifications_id_seq OWNED BY public.notifications.id;


--
-- Name: override_logs; Type: TABLE; Schema: public; Owner: postgres
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


ALTER TABLE public.override_logs OWNER TO postgres;

--
-- Name: override_logs_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.override_logs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.override_logs_id_seq OWNER TO postgres;

--
-- Name: override_logs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.override_logs_id_seq OWNED BY public.override_logs.id;


--
-- Name: override_usage_logs; Type: TABLE; Schema: public; Owner: postgres
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


ALTER TABLE public.override_usage_logs OWNER TO postgres;

--
-- Name: override_usage_logs_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.override_usage_logs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.override_usage_logs_id_seq OWNER TO postgres;

--
-- Name: override_usage_logs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.override_usage_logs_id_seq OWNED BY public.override_usage_logs.id;


--
-- Name: password_reset_tokens; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.password_reset_tokens (
    email character varying(255) NOT NULL,
    token character varying(255) NOT NULL,
    created_at timestamp(0) without time zone
);


ALTER TABLE public.password_reset_tokens OWNER TO postgres;

--
-- Name: pos_customers; Type: TABLE; Schema: public; Owner: postgres
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


ALTER TABLE public.pos_customers OWNER TO postgres;

--
-- Name: pos_customers_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.pos_customers_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.pos_customers_id_seq OWNER TO postgres;

--
-- Name: pos_customers_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.pos_customers_id_seq OWNED BY public.pos_customers.id;


--
-- Name: pos_payments; Type: TABLE; Schema: public; Owner: postgres
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


ALTER TABLE public.pos_payments OWNER TO postgres;

--
-- Name: pos_payments_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.pos_payments_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.pos_payments_id_seq OWNER TO postgres;

--
-- Name: pos_payments_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.pos_payments_id_seq OWNED BY public.pos_payments.id;


--
-- Name: pos_products; Type: TABLE; Schema: public; Owner: postgres
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
    is_tax_exempt boolean DEFAULT false NOT NULL
);


ALTER TABLE public.pos_products OWNER TO postgres;

--
-- Name: pos_products_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.pos_products_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.pos_products_id_seq OWNER TO postgres;

--
-- Name: pos_products_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.pos_products_id_seq OWNED BY public.pos_products.id;


--
-- Name: pos_services; Type: TABLE; Schema: public; Owner: postgres
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


ALTER TABLE public.pos_services OWNER TO postgres;

--
-- Name: pos_services_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.pos_services_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.pos_services_id_seq OWNER TO postgres;

--
-- Name: pos_services_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.pos_services_id_seq OWNED BY public.pos_services.id;


--
-- Name: pos_tax_rules; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.pos_tax_rules (
    id bigint NOT NULL,
    payment_method character varying(255) NOT NULL,
    tax_rate numeric(5,2) NOT NULL,
    is_active boolean DEFAULT true NOT NULL,
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.pos_tax_rules OWNER TO postgres;

--
-- Name: pos_tax_rules_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.pos_tax_rules_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.pos_tax_rules_id_seq OWNER TO postgres;

--
-- Name: pos_tax_rules_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.pos_tax_rules_id_seq OWNED BY public.pos_tax_rules.id;


--
-- Name: pos_terminals; Type: TABLE; Schema: public; Owner: postgres
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


ALTER TABLE public.pos_terminals OWNER TO postgres;

--
-- Name: pos_terminals_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.pos_terminals_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.pos_terminals_id_seq OWNER TO postgres;

--
-- Name: pos_terminals_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.pos_terminals_id_seq OWNED BY public.pos_terminals.id;


--
-- Name: pos_transaction_items; Type: TABLE; Schema: public; Owner: postgres
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
    CONSTRAINT pos_transaction_items_item_type_check CHECK (((item_type)::text = ANY ((ARRAY['product'::character varying, 'service'::character varying])::text[])))
);


ALTER TABLE public.pos_transaction_items OWNER TO postgres;

--
-- Name: pos_transaction_items_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.pos_transaction_items_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.pos_transaction_items_id_seq OWNER TO postgres;

--
-- Name: pos_transaction_items_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.pos_transaction_items_id_seq OWNED BY public.pos_transaction_items.id;


--
-- Name: pos_transactions; Type: TABLE; Schema: public; Owner: postgres
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
    CONSTRAINT pos_transactions_discount_type_check CHECK (((discount_type)::text = ANY ((ARRAY['percentage'::character varying, 'amount'::character varying])::text[])))
);


ALTER TABLE public.pos_transactions OWNER TO postgres;

--
-- Name: pos_transactions_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.pos_transactions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.pos_transactions_id_seq OWNER TO postgres;

--
-- Name: pos_transactions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.pos_transactions_id_seq OWNED BY public.pos_transactions.id;


--
-- Name: pra_logs; Type: TABLE; Schema: public; Owner: postgres
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


ALTER TABLE public.pra_logs OWNER TO postgres;

--
-- Name: pra_logs_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.pra_logs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.pra_logs_id_seq OWNER TO postgres;

--
-- Name: pra_logs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.pra_logs_id_seq OWNED BY public.pra_logs.id;


--
-- Name: pricing_plans; Type: TABLE; Schema: public; Owner: postgres
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


ALTER TABLE public.pricing_plans OWNER TO postgres;

--
-- Name: pricing_plans_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.pricing_plans_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.pricing_plans_id_seq OWNER TO postgres;

--
-- Name: pricing_plans_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.pricing_plans_id_seq OWNED BY public.pricing_plans.id;


--
-- Name: products; Type: TABLE; Schema: public; Owner: postgres
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


ALTER TABLE public.products OWNER TO postgres;

--
-- Name: products_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.products_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.products_id_seq OWNER TO postgres;

--
-- Name: products_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.products_id_seq OWNED BY public.products.id;


--
-- Name: province_tax_rules; Type: TABLE; Schema: public; Owner: postgres
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


ALTER TABLE public.province_tax_rules OWNER TO postgres;

--
-- Name: province_tax_rules_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.province_tax_rules_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.province_tax_rules_id_seq OWNER TO postgres;

--
-- Name: province_tax_rules_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.province_tax_rules_id_seq OWNED BY public.province_tax_rules.id;


--
-- Name: purchase_order_items; Type: TABLE; Schema: public; Owner: postgres
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


ALTER TABLE public.purchase_order_items OWNER TO postgres;

--
-- Name: purchase_order_items_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.purchase_order_items_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.purchase_order_items_id_seq OWNER TO postgres;

--
-- Name: purchase_order_items_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.purchase_order_items_id_seq OWNED BY public.purchase_order_items.id;


--
-- Name: purchase_orders; Type: TABLE; Schema: public; Owner: postgres
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


ALTER TABLE public.purchase_orders OWNER TO postgres;

--
-- Name: purchase_orders_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.purchase_orders_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.purchase_orders_id_seq OWNER TO postgres;

--
-- Name: purchase_orders_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.purchase_orders_id_seq OWNED BY public.purchase_orders.id;


--
-- Name: sector_tax_rules; Type: TABLE; Schema: public; Owner: postgres
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


ALTER TABLE public.sector_tax_rules OWNER TO postgres;

--
-- Name: sector_tax_rules_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.sector_tax_rules_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.sector_tax_rules_id_seq OWNER TO postgres;

--
-- Name: sector_tax_rules_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.sector_tax_rules_id_seq OWNED BY public.sector_tax_rules.id;


--
-- Name: security_logs; Type: TABLE; Schema: public; Owner: postgres
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


ALTER TABLE public.security_logs OWNER TO postgres;

--
-- Name: security_logs_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.security_logs_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.security_logs_id_seq OWNER TO postgres;

--
-- Name: security_logs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.security_logs_id_seq OWNED BY public.security_logs.id;


--
-- Name: sessions; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.sessions (
    id character varying(255) NOT NULL,
    user_id bigint,
    ip_address character varying(45),
    user_agent text,
    payload text NOT NULL,
    last_activity integer NOT NULL
);


ALTER TABLE public.sessions OWNER TO postgres;

--
-- Name: special_sro_rules; Type: TABLE; Schema: public; Owner: postgres
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


ALTER TABLE public.special_sro_rules OWNER TO postgres;

--
-- Name: special_sro_rules_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.special_sro_rules_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.special_sro_rules_id_seq OWNER TO postgres;

--
-- Name: special_sro_rules_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.special_sro_rules_id_seq OWNED BY public.special_sro_rules.id;


--
-- Name: subscription_invoices; Type: TABLE; Schema: public; Owner: postgres
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


ALTER TABLE public.subscription_invoices OWNER TO postgres;

--
-- Name: subscription_invoices_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.subscription_invoices_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.subscription_invoices_id_seq OWNER TO postgres;

--
-- Name: subscription_invoices_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.subscription_invoices_id_seq OWNED BY public.subscription_invoices.id;


--
-- Name: subscription_payments; Type: TABLE; Schema: public; Owner: postgres
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


ALTER TABLE public.subscription_payments OWNER TO postgres;

--
-- Name: subscription_payments_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.subscription_payments_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.subscription_payments_id_seq OWNER TO postgres;

--
-- Name: subscription_payments_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.subscription_payments_id_seq OWNED BY public.subscription_payments.id;


--
-- Name: subscriptions; Type: TABLE; Schema: public; Owner: postgres
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


ALTER TABLE public.subscriptions OWNER TO postgres;

--
-- Name: subscriptions_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.subscriptions_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.subscriptions_id_seq OWNER TO postgres;

--
-- Name: subscriptions_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.subscriptions_id_seq OWNED BY public.subscriptions.id;


--
-- Name: suppliers; Type: TABLE; Schema: public; Owner: postgres
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


ALTER TABLE public.suppliers OWNER TO postgres;

--
-- Name: suppliers_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.suppliers_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.suppliers_id_seq OWNER TO postgres;

--
-- Name: suppliers_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.suppliers_id_seq OWNED BY public.suppliers.id;


--
-- Name: system_controls; Type: TABLE; Schema: public; Owner: postgres
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


ALTER TABLE public.system_controls OWNER TO postgres;

--
-- Name: system_controls_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.system_controls_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.system_controls_id_seq OWNER TO postgres;

--
-- Name: system_controls_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.system_controls_id_seq OWNED BY public.system_controls.id;


--
-- Name: system_settings; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.system_settings (
    id bigint NOT NULL,
    key character varying(255) NOT NULL,
    value text NOT NULL,
    description character varying(255),
    created_at timestamp(0) without time zone,
    updated_at timestamp(0) without time zone
);


ALTER TABLE public.system_settings OWNER TO postgres;

--
-- Name: system_settings_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.system_settings_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.system_settings_id_seq OWNER TO postgres;

--
-- Name: system_settings_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.system_settings_id_seq OWNED BY public.system_settings.id;


--
-- Name: users; Type: TABLE; Schema: public; Owner: postgres
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


ALTER TABLE public.users OWNER TO postgres;

--
-- Name: users_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.users_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.users_id_seq OWNER TO postgres;

--
-- Name: users_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.users_id_seq OWNED BY public.users.id;


--
-- Name: vendor_risk_profiles; Type: TABLE; Schema: public; Owner: postgres
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


ALTER TABLE public.vendor_risk_profiles OWNER TO postgres;

--
-- Name: vendor_risk_profiles_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.vendor_risk_profiles_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.vendor_risk_profiles_id_seq OWNER TO postgres;

--
-- Name: vendor_risk_profiles_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.vendor_risk_profiles_id_seq OWNED BY public.vendor_risk_profiles.id;


--
-- Name: admin_announcements id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.admin_announcements ALTER COLUMN id SET DEFAULT nextval('public.admin_announcements_id_seq'::regclass);


--
-- Name: admin_audit_logs id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.admin_audit_logs ALTER COLUMN id SET DEFAULT nextval('public.admin_audit_logs_id_seq'::regclass);


--
-- Name: admin_users id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.admin_users ALTER COLUMN id SET DEFAULT nextval('public.admin_users_id_seq'::regclass);


--
-- Name: announcement_dismissals id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.announcement_dismissals ALTER COLUMN id SET DEFAULT nextval('public.announcement_dismissals_id_seq'::regclass);


--
-- Name: anomaly_logs id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.anomaly_logs ALTER COLUMN id SET DEFAULT nextval('public.anomaly_logs_id_seq'::regclass);


--
-- Name: audit_logs id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.audit_logs ALTER COLUMN id SET DEFAULT nextval('public.audit_logs_id_seq'::regclass);


--
-- Name: branches id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.branches ALTER COLUMN id SET DEFAULT nextval('public.branches_id_seq'::regclass);


--
-- Name: companies id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.companies ALTER COLUMN id SET DEFAULT nextval('public.companies_id_seq'::regclass);


--
-- Name: company_usage_stats id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.company_usage_stats ALTER COLUMN id SET DEFAULT nextval('public.company_usage_stats_id_seq'::regclass);


--
-- Name: compliance_reports id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.compliance_reports ALTER COLUMN id SET DEFAULT nextval('public.compliance_reports_id_seq'::regclass);


--
-- Name: compliance_scores id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.compliance_scores ALTER COLUMN id SET DEFAULT nextval('public.compliance_scores_id_seq'::regclass);


--
-- Name: customer_ledgers id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.customer_ledgers ALTER COLUMN id SET DEFAULT nextval('public.customer_ledgers_id_seq'::regclass);


--
-- Name: customer_profiles id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.customer_profiles ALTER COLUMN id SET DEFAULT nextval('public.customer_profiles_id_seq'::regclass);


--
-- Name: customer_tax_rules id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.customer_tax_rules ALTER COLUMN id SET DEFAULT nextval('public.customer_tax_rules_id_seq'::regclass);


--
-- Name: failed_jobs id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.failed_jobs ALTER COLUMN id SET DEFAULT nextval('public.failed_jobs_id_seq'::regclass);


--
-- Name: fbr_logs id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.fbr_logs ALTER COLUMN id SET DEFAULT nextval('public.fbr_logs_id_seq'::regclass);


--
-- Name: franchises id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.franchises ALTER COLUMN id SET DEFAULT nextval('public.franchises_id_seq'::regclass);


--
-- Name: global_hs_master id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.global_hs_master ALTER COLUMN id SET DEFAULT nextval('public.global_hs_master_id_seq'::regclass);


--
-- Name: hs_code_mappings id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.hs_code_mappings ALTER COLUMN id SET DEFAULT nextval('public.hs_code_mappings_id_seq'::regclass);


--
-- Name: hs_intelligence_logs id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.hs_intelligence_logs ALTER COLUMN id SET DEFAULT nextval('public.hs_intelligence_logs_id_seq'::regclass);


--
-- Name: hs_mapping_audit_logs id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.hs_mapping_audit_logs ALTER COLUMN id SET DEFAULT nextval('public.hs_mapping_audit_logs_id_seq'::regclass);


--
-- Name: hs_mapping_responses id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.hs_mapping_responses ALTER COLUMN id SET DEFAULT nextval('public.hs_mapping_responses_id_seq'::regclass);


--
-- Name: hs_master_global id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.hs_master_global ALTER COLUMN id SET DEFAULT nextval('public.hs_master_global_id_seq'::regclass);


--
-- Name: hs_rejection_history id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.hs_rejection_history ALTER COLUMN id SET DEFAULT nextval('public.hs_rejection_history_id_seq'::regclass);


--
-- Name: hs_unmapped_log id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.hs_unmapped_log ALTER COLUMN id SET DEFAULT nextval('public.hs_unmapped_log_id_seq'::regclass);


--
-- Name: hs_unmapped_queue id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.hs_unmapped_queue ALTER COLUMN id SET DEFAULT nextval('public.hs_unmapped_queue_id_seq'::regclass);


--
-- Name: hs_usage_patterns id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.hs_usage_patterns ALTER COLUMN id SET DEFAULT nextval('public.hs_usage_patterns_id_seq'::regclass);


--
-- Name: inventory_adjustments id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.inventory_adjustments ALTER COLUMN id SET DEFAULT nextval('public.inventory_adjustments_id_seq'::regclass);


--
-- Name: inventory_movements id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.inventory_movements ALTER COLUMN id SET DEFAULT nextval('public.inventory_movements_id_seq'::regclass);


--
-- Name: inventory_stocks id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.inventory_stocks ALTER COLUMN id SET DEFAULT nextval('public.inventory_stocks_id_seq'::regclass);


--
-- Name: invoice_activity_logs id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.invoice_activity_logs ALTER COLUMN id SET DEFAULT nextval('public.invoice_activity_logs_id_seq'::regclass);


--
-- Name: invoice_items id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.invoice_items ALTER COLUMN id SET DEFAULT nextval('public.invoice_items_id_seq'::regclass);


--
-- Name: invoices id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.invoices ALTER COLUMN id SET DEFAULT nextval('public.invoices_id_seq'::regclass);


--
-- Name: jobs id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.jobs ALTER COLUMN id SET DEFAULT nextval('public.jobs_id_seq'::regclass);


--
-- Name: migrations id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.migrations ALTER COLUMN id SET DEFAULT nextval('public.migrations_id_seq'::regclass);


--
-- Name: notifications id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.notifications ALTER COLUMN id SET DEFAULT nextval('public.notifications_id_seq'::regclass);


--
-- Name: override_logs id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.override_logs ALTER COLUMN id SET DEFAULT nextval('public.override_logs_id_seq'::regclass);


--
-- Name: override_usage_logs id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.override_usage_logs ALTER COLUMN id SET DEFAULT nextval('public.override_usage_logs_id_seq'::regclass);


--
-- Name: pos_customers id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.pos_customers ALTER COLUMN id SET DEFAULT nextval('public.pos_customers_id_seq'::regclass);


--
-- Name: pos_payments id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.pos_payments ALTER COLUMN id SET DEFAULT nextval('public.pos_payments_id_seq'::regclass);


--
-- Name: pos_products id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.pos_products ALTER COLUMN id SET DEFAULT nextval('public.pos_products_id_seq'::regclass);


--
-- Name: pos_services id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.pos_services ALTER COLUMN id SET DEFAULT nextval('public.pos_services_id_seq'::regclass);


--
-- Name: pos_tax_rules id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.pos_tax_rules ALTER COLUMN id SET DEFAULT nextval('public.pos_tax_rules_id_seq'::regclass);


--
-- Name: pos_terminals id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.pos_terminals ALTER COLUMN id SET DEFAULT nextval('public.pos_terminals_id_seq'::regclass);


--
-- Name: pos_transaction_items id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.pos_transaction_items ALTER COLUMN id SET DEFAULT nextval('public.pos_transaction_items_id_seq'::regclass);


--
-- Name: pos_transactions id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.pos_transactions ALTER COLUMN id SET DEFAULT nextval('public.pos_transactions_id_seq'::regclass);


--
-- Name: pra_logs id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.pra_logs ALTER COLUMN id SET DEFAULT nextval('public.pra_logs_id_seq'::regclass);


--
-- Name: pricing_plans id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.pricing_plans ALTER COLUMN id SET DEFAULT nextval('public.pricing_plans_id_seq'::regclass);


--
-- Name: products id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.products ALTER COLUMN id SET DEFAULT nextval('public.products_id_seq'::regclass);


--
-- Name: province_tax_rules id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.province_tax_rules ALTER COLUMN id SET DEFAULT nextval('public.province_tax_rules_id_seq'::regclass);


--
-- Name: purchase_order_items id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.purchase_order_items ALTER COLUMN id SET DEFAULT nextval('public.purchase_order_items_id_seq'::regclass);


--
-- Name: purchase_orders id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.purchase_orders ALTER COLUMN id SET DEFAULT nextval('public.purchase_orders_id_seq'::regclass);


--
-- Name: sector_tax_rules id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.sector_tax_rules ALTER COLUMN id SET DEFAULT nextval('public.sector_tax_rules_id_seq'::regclass);


--
-- Name: security_logs id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.security_logs ALTER COLUMN id SET DEFAULT nextval('public.security_logs_id_seq'::regclass);


--
-- Name: special_sro_rules id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.special_sro_rules ALTER COLUMN id SET DEFAULT nextval('public.special_sro_rules_id_seq'::regclass);


--
-- Name: subscription_invoices id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.subscription_invoices ALTER COLUMN id SET DEFAULT nextval('public.subscription_invoices_id_seq'::regclass);


--
-- Name: subscription_payments id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.subscription_payments ALTER COLUMN id SET DEFAULT nextval('public.subscription_payments_id_seq'::regclass);


--
-- Name: subscriptions id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.subscriptions ALTER COLUMN id SET DEFAULT nextval('public.subscriptions_id_seq'::regclass);


--
-- Name: suppliers id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.suppliers ALTER COLUMN id SET DEFAULT nextval('public.suppliers_id_seq'::regclass);


--
-- Name: system_controls id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.system_controls ALTER COLUMN id SET DEFAULT nextval('public.system_controls_id_seq'::regclass);


--
-- Name: system_settings id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.system_settings ALTER COLUMN id SET DEFAULT nextval('public.system_settings_id_seq'::regclass);


--
-- Name: users id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.users ALTER COLUMN id SET DEFAULT nextval('public.users_id_seq'::regclass);


--
-- Name: vendor_risk_profiles id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.vendor_risk_profiles ALTER COLUMN id SET DEFAULT nextval('public.vendor_risk_profiles_id_seq'::regclass);


--
-- Data for Name: admin_announcements; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.admin_announcements (id, title, message, type, target, target_company_id, is_active, expires_at, created_by, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: admin_audit_logs; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.admin_audit_logs (id, admin_id, action, target_type, target_id, metadata, created_at, updated_at) FROM stdin;
1	1	Company approved	Company	12	{"name":"Test Trading Company"}	2026-03-06 10:53:48	2026-03-06 10:53:48
2	1	Company suspended	Company	12	{"name":"Test Trading Company"}	2026-03-06 10:54:06	2026-03-06 10:54:06
3	1	Company approved	Company	12	{"name":"Test Trading Company"}	2026-03-06 11:00:16	2026-03-06 11:00:16
4	1	Company approved	Company	12	{"name":"Test Trading Company"}	2026-03-06 16:25:37	2026-03-06 16:25:37
5	1	Company approved	Company	12	{"name":"Test Trading Company"}	2026-03-06 16:28:16	2026-03-06 16:28:16
6	1	Company approved	Company	12	{"name":"Test Trading Company"}	2026-03-06 16:30:32	2026-03-06 16:30:32
7	1	Subscription deactivated	Subscription	11	\N	2026-03-07 08:00:50	2026-03-07 08:00:50
\.


--
-- Data for Name: admin_users; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.admin_users (id, name, email, password, role, created_at, updated_at, remember_token) FROM stdin;
1	Super Admin	admin@taxnest.com	$2y$12$D1jHvV2BB2T7aN4bcMFYLObnuU1hEwDQ6kEl6Mt0Bt8jWnTCcJ9Cu	super_admin	2026-03-06 04:39:51	2026-03-06 04:39:51	2NV4u81ayYsbwKFU1uCwU25phrSJ2fD4GrNKQfwRsBIa2hKv1qALq5iciFRL
\.


--
-- Data for Name: announcement_dismissals; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.announcement_dismissals (id, announcement_id, user_id, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: anomaly_logs; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.anomaly_logs (id, company_id, type, severity, description, metadata, resolved, created_at, updated_at) FROM stdin;
1	7	price_deviation	high	Item #0: Price Rs. 50.00 is 81% below avg (Rs. 260.21) for HS 3105.3000	{"hs_code":"3105.3000","current_price":50,"average_price":260.21,"deviation_percent":80.8,"direction":"below","invoice_id":14}	f	2026-02-14 13:48:12	2026-02-14 13:48:12
2	7	price_deviation	high	Item #0: Price Rs. 10.00 is 96% below avg (Rs. 233.93) for HS 3105.3000	{"hs_code":"3105.3000","current_price":10,"average_price":233.93,"deviation_percent":95.7,"direction":"below","invoice_id":17}	f	2026-02-14 16:45:59	2026-02-14 16:45:59
3	7	price_deviation	high	Item #0: Price Rs. 1,040.84 is 329% above avg (Rs. 242.69) for HS 3105.3000	{"hs_code":"3105.3000","current_price":1040.84,"average_price":242.69,"deviation_percent":328.9,"direction":"above","invoice_id":21}	f	2026-02-15 17:44:52	2026-02-15 17:44:52
\.


--
-- Data for Name: audit_logs; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.audit_logs (id, company_id, user_id, action, entity_type, entity_id, old_values, new_values, ip_address, sha256_hash, created_at) FROM stdin;
1	\N	1	company_approved	Company	4	\N	{"name":"New Test Corp"}	127.0.0.1	bcbffd33492cd4eca8a4d36911936a615da486849063f15eb60d1de217d2eeb0	2026-02-11 13:10:53
2	\N	\N	test_internal_bypass	Company	2	\N	{"bypass_type":"invoice_limit","is_internal":true,"test":true}	127.0.0.1	a6443136fdfb0d70e517a2ef5e044f5a265980a1773e5170722cd475b13a8834	2026-02-11 17:41:03
3	2	4	internal_bypass_test	Company	2	\N	{"bypass_type":"invoice_limit","is_internal":true}	127.0.0.1	6e5b93b9aa4499fdaaf2c29ed00914433900ff25f6f39194f560394217ad4c0d	2026-02-11 17:41:22
4	2	4	invoice_created	Invoice	8	\N	{"invoice_number":"DEMOT-000001","buyer_name":"WALK IN CUSTOMER","total_amount":70.8,"document_type":"Sale Invoice"}	10.83.12.104	1536e550e87147f55b138c853fa29f3bb68b817d51c07b957387172588b9a9b7	2026-02-12 07:06:09
5	2	4	invoice_submitted	Invoice	8	\N	{"mode":"smart","compliance_score":70,"risk_level":"MODERATE"}	10.83.3.120	c537acb89d0d8f06d0794a69941632b19eaebd56d1297ca99e5902e0b23983a8	2026-02-12 07:06:16
6	2	4	invoice_edited	Invoice	8	{"buyer_name":"WALK IN CUSTOMER","buyer_ntn":null,"total_amount":"70.80"}	{"buyer_name":"WALK IN CUSTOMER","buyer_ntn":null,"total_amount":70.8}	10.83.0.98	9b0d4e3b7254e0ff92d238c7f802d873cebae5818f67d6de8ae8ed446d8ae91d	2026-02-12 08:50:20
7	2	4	invoice_submitted	Invoice	8	\N	{"mode":"direct_mis","override_reason":"updated and remove error"}	10.83.6.125	7cf415379bce041b6902829ffc518b1a7744a95421b3dc909e9e21058a707985	2026-02-12 08:51:11
8	2	4	invoice_retry	Invoice	8	\N	{"retried_by":"Demo Company Admin"}	10.83.12.104	239e1ad01627d7e5a03d2bebf3938d8c3a63639e5112ab69357be191c2333ad2	2026-02-12 09:41:50
9	2	4	invoice_retry	Invoice	8	\N	{"retried_by":"Demo Company Admin"}	10.83.12.104	d087c0f8d00f8999fe270e92c93417b55ff4649a10b8697c01f9552b17f1910c	2026-02-12 11:47:13
10	7	10	fbr_settings_changed	Company	7	\N	{"environment":"sandbox","registration_no":"3620291786117"}	127.0.0.1	e767d6923b1e41f6b555f732dadeb2b10a4313d351d3b842cb76da73f4ab3638	2026-02-13 05:14:41
11	7	10	fbr_settings_changed	Company	7	\N	{"environment":"sandbox","registration_no":"3620291786117"}	10.83.11.158	1943e6f1febdfb9bbd7b5d5b108291183690a60e32d4d8e1baca8b4c7edcd66d	2026-02-13 05:29:20
12	7	10	invoice_created	Invoice	9	\N	{"invoice_number":"ZIACO-000001","buyer_name":"walk in customer","total_amount":273.22,"document_type":"Sale Invoice"}	10.83.3.120	25ff57f763945d9faea7bd1542a543c8adfefa3f8733e6761de1f18084f49d5c	2026-02-13 05:31:22
13	7	10	invoice_submitted	Invoice	9	\N	{"mode":"smart","compliance_score":55,"risk_level":"HIGH"}	10.83.3.120	31436aea33df02ae44d13bc01d051e547d0cdf1a4713d52be267a6246fee5cd7	2026-02-13 05:31:31
14	7	10	invoice_submitted	Invoice	9	\N	{"mode":"smart","compliance_score":55,"risk_level":"HIGH"}	10.83.2.144	94e950d93b738d358adae16c5c1e0a8e237cef8a763fdd34125f5de65879f3c6	2026-02-13 07:35:18
15	7	10	invoice_retry	Invoice	9	\N	{"retried_by":"ZIA UR REHMAN"}	10.83.6.125	607b9b7842622eb5577f84b68a2376b505fd7a4deb7651cd6090e7c3f763de29	2026-02-13 08:26:51
16	7	10	invoice_edited	Invoice	9	{"buyer_name":"walk in customer","buyer_ntn":null,"total_amount":"273.22"}	{"buyer_name":"JAWAD","buyer_ntn":null,"total_amount":273.22}	10.83.4.235	7f670342f076fc477dd5abf9642de477ffa867f7cf1dd7ad21c658c8d95828f2	2026-02-13 09:48:46
17	7	10	invoice_submitted	Invoice	9	\N	{"mode":"direct_mis","override_reason":"OK YAR KR DO SUBMIIT"}	10.83.4.235	836bc0bd7b83a9a6dc9bc29da04b60f1625a0b018c0a1b5bcba8b3e76a849644	2026-02-13 09:49:14
18	7	10	invoice_edited	Invoice	9	{"buyer_name":"JAWAD","buyer_ntn":null,"total_amount":"273.22"}	{"buyer_name":"CONFIRM","buyer_ntn":null,"total_amount":273.22}	10.83.0.98	3d641a383b65e37a1e744006874cd59d2e91c2c5c2c23666f96c39bf67475d73	2026-02-13 09:52:47
19	7	10	invoice_submitted	Invoice	9	\N	{"mode":"direct_mis","override_reason":"OK YEH INVOICE TRY KAY LIYE HAI AB"}	10.83.0.98	fa48e36db1b3282c6a6c71af6eacc0a72bb41d11f4c1e41dc0600fb70429efa8	2026-02-13 09:53:03
20	7	10	invoice_edited	Invoice	9	{"buyer_name":"CONFIRM","buyer_ntn":null,"total_amount":"273.22"}	{"buyer_name":"NISAR","buyer_ntn":null,"total_amount":273.22}	10.83.0.98	cc78a8feff399247e9004205b70ea48366a2b247e27a931023ac83de72c60bc5	2026-02-13 09:56:33
21	7	10	invoice_submitted	Invoice	9	\N	{"mode":"direct_mis","override_reason":"KR DO SUBMIT"}	10.83.0.98	fef5c1c937d2e52c3830b74118da075eeac4c53c2a398c3e6cc0d3fdfc046819	2026-02-13 09:57:10
22	7	10	invoice_resubmitted	Invoice	9	\N	{"fbr_invoice_number":"3620291786117DI1770976705355","environment":"production"}	10.83.0.98	26aab17a33fc8baf6605d37396e907efe82e9e859d00ad26ec5732f8313459f5	2026-02-13 09:58:25
23	7	10	invoice_retry	Invoice	9	\N	{"retried_by":"ZIA UR REHMAN"}	10.83.0.98	326d952539d31e9f8961360f644f17ca69aa90211d6d6eb4954c5a3ffe563416	2026-02-13 09:58:55
24	7	10	invoice_created	Invoice	10	\N	{"invoice_number":"3620291786117DI1771050183903","buyer_name":"WALK IN CUSTOMER","total_amount":546.44,"document_type":"Sale Invoice"}	10.83.1.129	a96be1d91450c985a378aba509a567595ab95c23786ac8fa900b56e64303484f	2026-02-14 06:23:03
25	7	10	invoice_submitted	Invoice	10	\N	{"mode":"smart","compliance_score":55,"risk_level":"HIGH"}	10.83.1.129	4404bf700db11b5875239a4236a855221e9c786547ece9b9da72750f93c06422	2026-02-14 06:23:37
26	7	10	invoice_manually_confirmed	Invoice	10	\N	{"confirmed_by":"ZIA UR REHMAN"}	10.83.13.14	5289e67fe88632d0294840b7347ab6668108ebd027b387cadb0ddc66b5511d3f	2026-02-14 06:32:14
27	7	10	fbr_number_updated	Invoice	10	\N	{"old_number":null,"new_number":"3620291786117DIACOLWW080848","updated_by":"ZIA UR REHMAN"}	10.83.7.231	2ce10f81f8133d977129bc77d09d5517f35013bb91b75b83d2d844a07f13d568	2026-02-14 06:49:27
28	7	10	invoice_created	Invoice	11	\N	{"invoice_number":"36381144DI1771055182422","buyer_name":"walk in cutomer","total_amount":819.66,"document_type":"Sale Invoice"}	10.83.11.49	0e8860c0490581d96b1e6f6aa0272bbaa0732d273cbdba5660848cc591a3f5a4	2026-02-14 07:46:22
29	7	10	invoice_submitted	Invoice	11	\N	{"mode":"smart","compliance_score":55,"risk_level":"HIGH"}	10.83.7.231	04d47ad81010aef646c309617c548a025e27543253c5122aa1eeb0760d7021eb	2026-02-14 08:08:53
30	7	10	fbr_settings_changed	Company	7	\N	{"environment":"production","registration_no":"3620291786117"}	10.83.11.49	c966e5db4bb38bef7b8e4db6a8c3b455e28a8ae15cadf783963a2dfd2c1be435	2026-02-14 08:12:27
31	7	10	fbr_settings_changed	Company	7	\N	{"environment":"production","registration_no":"3620291786117"}	10.83.4.235	f7fc1bf1855be69fcc3d6cbc7431f735eda7add0a7f61825fe2cd6f2e6adaf7d	2026-02-14 08:31:04
32	7	10	fbr_settings_changed	Company	7	\N	{"environment":"production","registration_no":"3620291786117"}	10.83.4.235	6a4dd2dc41d5b8d9ff54721e44a5a5673961cd3860fe0f049b351196d7211e3c	2026-02-14 08:37:05
33	7	10	fbr_settings_changed	Company	7	\N	{"environment":"production","registration_no":"3620291786117"}	10.83.4.235	2685ce2e6875dd0f3acbdca2e1d89de7ed334dde42caf2ec74e8286dd8937cbb	2026-02-14 08:40:06
34	7	10	fbr_settings_changed	Company	7	\N	{"environment":"production","registration_no":"3620291786117"}	10.83.7.231	e5f3a92459ffbec4d810dd08c00081e6155a32c67adf93d89e5af5374615cc74	2026-02-14 08:43:39
35	7	10	fbr_settings_changed	Company	7	\N	{"environment":"production","registration_no":"3620291786117"}	10.83.7.231	3ed75e7656e11e944b59d538d3abca645982a2423494cea70d9a842254d465f1	2026-02-14 08:46:30
36	7	10	fbr_settings_changed	Company	7	\N	{"environment":"production","registration_no":"3620291786117"}	10.83.4.235	ebfd1f9602a1c32772045905a86c29f5019e3abcd7b224b4b3414915703e7fba	2026-02-14 08:47:18
37	7	10	fbr_settings_changed	Company	7	\N	{"environment":"production","registration_no":"3620291786117"}	10.83.7.231	74d84de3be0cb82e1c292c3f6216757d04c1777daffd286749b14bb39ddb3aca	2026-02-14 08:48:42
38	7	10	fbr_settings_changed	Company	7	\N	{"environment":"production","registration_no":"3620291786117"}	10.83.4.235	56ed6e80ac4b16155ee1b1dbd6570b338b0830f7272707ba8b5926c32b6dc2d4	2026-02-14 08:51:01
39	7	10	fbr_settings_changed	Company	7	\N	{"environment":"production","registration_no":"3620291786117"}	10.83.4.235	c7d6429eb32ed0f9ba01de35752e89e02f6eabb73dfbba850379697af650e5b6	2026-02-14 08:53:54
40	7	10	fbr_settings_changed	Company	7	\N	{"environment":"production","registration_no":"3620291786117"}	10.83.3.32	e311d5bfdf69d1058c0bb5f7353c85e4f1b137a7f798d50a308e2ef339f762fa	2026-02-14 09:08:15
41	7	10	invoice_created	Invoice	12	\N	{"invoice_number":"36381144DI1771062897784","buyer_name":"walk in cutomer","total_amount":273.22,"document_type":"Sale Invoice"}	10.83.9.201	c325baae6484def5e29fc8012af589633739b4be8057b0c749fd4c4d39574053	2026-02-14 09:54:57
42	7	10	fbr_settings_changed	Company	7	\N	{"environment":"production","registration_no":"3620291786117"}	10.83.9.201	1bc913127eebd9a159b0402d4d59a275955972febf36ffcfa6e1eb92b9f26b0a	2026-02-14 10:18:46
43	\N	\N	invoice_fbr_failed	Invoice	12	\N	{"failure_type":"token_error","mode":"sync"}	127.0.0.1	841cc09054468fd8314bc6f4b7450b71e9fbd454fb6d87bec472c419b8cbb4b3	2026-02-14 10:20:58
44	7	10	invoice_edited	Invoice	11	{"buyer_name":"walk in cutomer","buyer_ntn":null,"total_amount":"819.66"}	{"buyer_name":"walk in cutomer","buyer_ntn":null,"total_amount":819.66}	10.83.11.49	4703124a1fb41876b4f24d1d1ba01ea7a1abfaa7dbbc73f9e36cd33c58fd541d	2026-02-14 10:25:05
45	7	10	invoice_submitted	Invoice	11	\N	{"mode":"smart","compliance_score":55,"risk_level":"HIGH"}	10.83.4.235	00460da66c7ab0b54eca8cca89ead69369807912fc5de09cc840ef0136ab2f4a	2026-02-14 10:25:15
46	7	10	fbr_number_updated	Invoice	11	\N	{"old_number":null,"new_number":"3620291786117DIACOPXI193297","updated_by":"ZIA UR REHMAN"}	10.83.3.32	f65dead6a19404829386f82b592512cae39ef79732449c7b094f40dc6d03ca97	2026-02-14 12:29:12
47	7	10	invoice_created	Invoice	13	\N	{"invoice_number":"36381144DI1771073421293","buyer_name":"Abrar Ahmad","total_amount":819.66,"document_type":"Sale Invoice"}	10.83.6.39	72d8bf390cee972f19fe113036cffb30934d7d2a04da56718f8e95d1d39574e4	2026-02-14 12:50:21
48	7	10	invoice_submitted	Invoice	13	\N	{"mode":"smart","compliance_score":55,"risk_level":"HIGH"}	10.83.1.3	c927d87d284518483d1afdd95b90c2be2692437509c6150aa5aabf1999432644	2026-02-14 12:50:25
49	7	10	invoice_verification_rejected	Invoice	13	\N	{"rejected_by":"ZIA UR REHMAN"}	10.83.11.49	2780a97909714f8657a677e4a3e2d06aaa5a5e903f97e27fe2928df914fe357a	2026-02-14 13:05:54
50	7	10	invoice_submitted	Invoice	13	\N	{"mode":"smart","compliance_score":55,"risk_level":"HIGH"}	10.83.4.6	880f492ac59f57d29ba328699b1aa04dee0c42c6ce2a7b9a6e52fea819c08939	2026-02-14 13:06:02
51	7	10	invoice_submitted	Invoice	13	\N	{"mode":"smart","compliance_score":55,"risk_level":"HIGH"}	10.83.4.6	12c1a092a7586d2ccd968c2bd67731dfb34b0204e5d6569e9abc84651a8dfa3b	2026-02-14 13:08:26
52	7	10	invoice_submitted	Invoice	13	\N	{"mode":"smart","compliance_score":55,"risk_level":"HIGH"}	10.83.4.6	6f3086a22f8ea546257872acba2c2e8c8c79b353ec6ff4011dfd9bb3fdc317e5	2026-02-14 13:46:34
53	7	10	invoice_created	Invoice	14	\N	{"invoice_number":"3638114-4DI1771076887931","buyer_name":"rayan","total_amount":52.5,"document_type":"Sale Invoice"}	10.83.4.6	802771c570c42b50c35fba6b10532622909441e226c6cc9012813841ab9d2135	2026-02-14 13:48:07
54	7	10	invoice_submitted	Invoice	14	\N	{"mode":"smart","compliance_score":55,"risk_level":"HIGH"}	10.83.4.6	4f768c52c591d401d589ed1ae7dc351aeb6dd7bd2f4610ec4ee2c29d642e3953	2026-02-14 13:48:12
55	7	10	invoice_submitted	Invoice	14	\N	{"mode":"smart","compliance_score":55,"risk_level":"HIGH"}	10.83.4.6	5ec47a7275b080b45d59908799509af442aa1319a74066fddf2a4ae56bf586f9	2026-02-14 13:52:24
56	7	10	invoice_submitted	Invoice	14	\N	{"mode":"smart","compliance_score":55,"risk_level":"HIGH"}	10.83.4.6	9d8005b971ab79282ba5e2d11764c6373a6abfa66939aa7066d46d510f1f5038	2026-02-14 13:55:54
57	7	10	invoice_fbr_success	Invoice	14	\N	{"fbr_invoice_number":"3620291786117DIACOSDC566199","environment":"production","mode":"sync"}	10.83.4.6	76bcee5fe0addd8d2f58700c78a2b323d431fb94132d0a4e634868823fcdbf3c	2026-02-14 13:55:56
58	7	10	invoice_submitted	Invoice	13	\N	{"mode":"smart","compliance_score":55,"risk_level":"HIGH"}	10.83.13.14	3522df3302476175a19107c204d6fc392423e450e770f2f3ccefaa17f30dbacd	2026-02-14 13:56:55
59	7	10	invoice_submitted	Invoice	13	\N	{"mode":"smart","compliance_score":55,"risk_level":"HIGH"}	10.83.13.14	8641ec0d6108bafbbd4ebf5fac5c6bef22d4472d60a7d5ee645fd085ea7fef71	2026-02-14 13:57:02
60	7	10	invoice_submitted	Invoice	13	\N	{"mode":"smart","compliance_score":55,"risk_level":"HIGH"}	10.83.4.6	6d9608846f32eac606ac6a490b3722590fb2198e4437dc38487512cb7eb23ac3	2026-02-14 13:57:11
61	7	10	invoice_submitted	Invoice	13	\N	{"mode":"smart","compliance_score":55,"risk_level":"HIGH"}	10.83.4.6	9208efe2dcfbe0e6c1da53cc4443b6bdb56e57a9a6f600605efa03ca0287a7c6	2026-02-14 13:59:41
62	7	10	invoice_submitted	Invoice	13	\N	{"mode":"smart","compliance_score":55,"risk_level":"HIGH"}	10.83.4.6	c79f069bac2aed9f51c42e68b953f1d57fdf938be45fc6830acb39ae267fc829	2026-02-14 13:59:53
63	7	10	invoice_fbr_success	Invoice	13	\N	{"fbr_invoice_number":"3620291786117DIACOSGL329267","environment":"production","mode":"sync"}	10.83.4.6	b735a31946dc2819a2abc892e97b54bf9a65576ae6b05b54702470307c8c0ac0	2026-02-14 13:59:54
64	7	10	invoice_created	Invoice	15	\N	{"invoice_number":"3620291786117DI1771077882570","buyer_name":"qasim","total_amount":1366.1,"document_type":"Sale Invoice"}	10.83.4.6	3d683653dbec527c37d526ab9764bec90998ed28252e815854fcc0d718b4e131	2026-02-14 14:04:42
65	7	10	invoice_submitted	Invoice	15	\N	{"mode":"smart","compliance_score":55,"risk_level":"HIGH"}	10.83.13.14	92a617a0f62b93647e880d180807e4969850607178c4fdc636b3ead64f6f0345	2026-02-14 14:05:27
66	7	10	invoice_submitted	Invoice	15	\N	{"mode":"smart","compliance_score":55,"risk_level":"HIGH"}	10.83.4.6	2b56ef91e8682997f9e76cab02580b6bf2ad7a1cedf55d511ff26ede9ccc1539	2026-02-14 14:05:38
67	7	10	fbr_payload_validation	Invoice	15	\N	{"status":"invalid","errors":[""]}	10.83.13.14	a5dea3151f864802f396a93ad7f1e60ae65e6de1d4baeabae1b69f4cf18bc0d7	2026-02-14 14:05:46
68	7	10	invoice_submitted	Invoice	15	\N	{"mode":"smart","compliance_score":55,"risk_level":"HIGH"}	10.83.4.6	4b04fd7f9adf52d2aaf50b975157d632e8b231364b03e31f6007f9ab7502f7f0	2026-02-14 14:09:01
69	7	10	invoice_submitted	Invoice	15	\N	{"mode":"smart","compliance_score":55,"risk_level":"HIGH"}	10.83.13.14	d03a83b79e8b8de05c445931b614cd088b0d401fdecec0f170510e23b3e87d50	2026-02-14 14:09:09
70	7	10	invoice_edited	Invoice	15	{"buyer_name":"qasim","buyer_ntn":null,"total_amount":"1366.10"}	{"buyer_name":"sajid","buyer_ntn":null,"total_amount":1366.1}	10.83.13.14	110854f029ef0885b2810e8ab74f0330491fb0ee37134ebe51540d48a2115e3b	2026-02-14 14:09:54
71	7	10	invoice_submitted	Invoice	15	\N	{"mode":"smart","compliance_score":55,"risk_level":"HIGH"}	10.83.4.6	32b9531253da07b0600e615d578d771783c7396a31bdf6e5f33b536f239a3e1f	2026-02-14 14:09:59
72	7	10	invoice_submitted	Invoice	15	\N	{"mode":"smart","compliance_score":55,"risk_level":"HIGH"}	10.83.12.54	3232863e7f53b8be50f7bacce1ac28f77f0accf3ea60102001607cc88556ed94	2026-02-14 14:17:25
73	7	10	invoice_edited	Invoice	15	{"buyer_name":"sajid","buyer_ntn":null,"total_amount":"1366.10"}	{"buyer_name":"waheed","buyer_ntn":null,"total_amount":1366.1}	10.83.11.49	0cc51c4ec098d4445367a7fd190fbcf041a2daf0492ea3def6ce803d0a1e9896	2026-02-14 14:26:06
74	7	10	invoice_submitted	Invoice	15	\N	{"mode":"smart","compliance_score":55,"risk_level":"HIGH"}	10.83.12.54	bd0c93db88c663787f069405301eb47cfe8d8b2e79a1386a64c4c22da98a89bd	2026-02-14 14:26:17
75	7	10	invoice_submitted	Invoice	15	\N	{"mode":"smart","compliance_score":55,"risk_level":"HIGH"}	10.83.1.3	82e0e91b7cf3031a4d723109d15cdfb86e4f20eb45c50aeefa4e916bd395eb25	2026-02-14 14:40:08
76	7	10	invoice_edited	Invoice	15	{"buyer_name":"waheed","buyer_ntn":null,"total_amount":"1366.10"}	{"buyer_name":"Naeem","buyer_ntn":null,"total_amount":1366.1}	10.83.9.10	72659a9b387995219f1130093d9b57f8565d88ece0c738d6bfc07aa0ce1d06ba	2026-02-14 15:11:50
77	7	10	invoice_submitted	Invoice	15	\N	{"mode":"smart","compliance_score":55,"risk_level":"HIGH"}	10.83.1.3	8dbd7b62d8e001fc375c59b643d67ef0791edc061947e190b7bedd093b7fb764	2026-02-14 15:11:57
78	7	10	invoice_edited	Invoice	15	{"buyer_name":"Naeem","buyer_ntn":null,"total_amount":"1366.10"}	{"buyer_name":"National","buyer_ntn":null,"total_amount":1366.1}	10.83.1.3	190252d578c5a6a67a4ffdc99ecc99b61b40b16d21ed388e3cfe2cc924da287c	2026-02-14 15:21:18
79	7	10	invoice_submitted	Invoice	15	\N	{"mode":"smart","compliance_score":55,"risk_level":"HIGH"}	10.83.13.14	7fd8d49181228cbf2a6c5f938018707c6aaa922c188468714bba10de7973d49e	2026-02-14 15:21:25
80	7	10	invoice_submitted	Invoice	15	\N	{"mode":"smart","compliance_score":55,"risk_level":"HIGH"}	10.83.1.3	38042b7a5d391f7d65a37bec9d95a90cef35ccf7d1677ba3e202ebb046a38ec1	2026-02-14 15:32:14
81	7	10	invoice_fbr_failed	Invoice	15	\N	{"failure_type":"token_error","mode":"sync"}	10.83.1.3	1600c21beea696439b5ca1746969d51d8d67f7ad03c3d470b1aa49d7cfb116bb	2026-02-14 15:32:45
82	7	10	invoice_submitted	Invoice	15	\N	{"mode":"smart","compliance_score":55,"risk_level":"HIGH"}	10.83.4.6	3c971080dffafeb61fbf24c632c5a9192c953e8027b8ae229a91a665f363f964	2026-02-14 15:33:04
83	7	10	invoice_fbr_failed	Invoice	15	\N	{"failure_type":"token_error","mode":"sync"}	10.83.4.6	a10530ed992f5ae707e70bed8854c5702099a4100c41b42e100ef62973072aba	2026-02-14 15:33:34
84	7	10	invoice_edited	Invoice	15	{"buyer_name":"National","buyer_ntn":null,"total_amount":"1366.10"}	{"buyer_name":"Ali","buyer_ntn":null,"total_amount":819.66}	10.83.4.9	70d50a05bb27f429bf6d1ac169e67331d30d2e9c2d963b98892b5ea5df8a2662	2026-02-14 15:39:02
85	7	10	invoice_submitted	Invoice	15	\N	{"mode":"smart","compliance_score":55,"risk_level":"HIGH"}	10.83.4.6	d7cd8f6c39f30940737f516d267605176aa31b0e9dd0462b967a1284c759790f	2026-02-14 15:41:22
86	7	10	invoice_fbr_failed	Invoice	15	\N	{"failure_type":"payload_error","mode":"sync"}	10.83.4.6	a47e59796952d65638c3d54916efae80080bf3160b84dda9f361c24a3f5681cf	2026-02-14 15:41:52
87	7	10	invoice_submitted	Invoice	15	\N	{"mode":"smart","compliance_score":55,"risk_level":"HIGH"}	10.83.6.39	406ca9408d0de54ac86ea5edabe62b52774ba149185a2dec1298bc602b846841	2026-02-14 15:59:07
88	7	10	invoice_fbr_failed	Invoice	15	\N	{"failure_type":"payload_error","mode":"sync"}	10.83.6.39	12ffd8fc58c03f5c9c9e7a69b5c878cab1999beb7bbd0721987175272eb0c1cc	2026-02-14 15:59:37
89	7	10	invoice_submitted	Invoice	15	\N	{"mode":"smart","compliance_score":55,"risk_level":"HIGH"}	10.83.8.57	98187276906c8622aeff27c0a12aab4434ebad80f1a1ec26e764a0158ca66878	2026-02-14 16:00:18
90	7	10	invoice_fbr_failed	Invoice	15	\N	{"failure_type":"payload_error","mode":"sync"}	10.83.8.57	271ed52d371217c15d4b2c126c288f078fb6fa0279b2a50c36a8c28f95fe3ad0	2026-02-14 16:00:48
91	7	10	invoice_submitted	Invoice	15	\N	{"mode":"smart","compliance_score":55,"risk_level":"HIGH"}	10.83.13.14	271c59281686deb3968e407a5574441dd713ad4d95549366f980c1dd13ba1047	2026-02-14 16:02:18
92	7	10	invoice_fbr_failed	Invoice	15	\N	{"failure_type":"payload_error","mode":"sync"}	10.83.13.14	8c98e7c70ed4c7e58e90fa55d65ec064b328aa43d96f0328a779c8fd18aed17b	2026-02-14 16:02:20
93	7	10	invoice_submitted	Invoice	15	\N	{"mode":"smart","compliance_score":55,"risk_level":"HIGH"}	10.83.8.57	d9bdf73a0efd47eb3979d94f616eb459db006f1aa86638ce233d41332bab288b	2026-02-14 16:06:11
94	7	10	invoice_fbr_failed	Invoice	15	\N	{"failure_type":"payload_error","mode":"sync"}	10.83.8.57	f4b73ea06e790ee14da8c3b034c707e8e88613d8b18d48c04f91274544b6ce33	2026-02-14 16:06:12
95	7	10	invoice_submitted	Invoice	15	\N	{"mode":"smart","compliance_score":55,"risk_level":"HIGH"}	10.83.13.14	3510f6ed0edd5f81f3d4543a1153169235d4a5e6ff98788a6d33847e0eee6ec4	2026-02-14 16:16:40
96	7	10	invoice_fbr_failed	Invoice	15	\N	{"failure_type":"payload_error","mode":"sync"}	10.83.13.14	a8cde634a9ee5b7142443870229553761e1a228dabcd5fde2af3fdbdb4af9501	2026-02-14 16:16:43
97	7	10	invoice_submitted	Invoice	15	\N	{"mode":"smart","compliance_score":55,"risk_level":"HIGH"}	10.83.9.16	f77b8707a8b8cecc92968a193a395f11ec8c33007cde6cc31b579932839c329b	2026-02-14 16:23:34
98	7	10	invoice_fbr_failed	Invoice	15	\N	{"failure_type":"payload_error","mode":"sync"}	10.83.9.16	91bc0c75fab8d2d674a532d74cb0b40dedcdc938f70660a6c5831f7731b51ea9	2026-02-14 16:23:37
99	7	10	invoice_edited	Invoice	15	{"buyer_name":"Ali","buyer_ntn":null,"total_amount":"819.66"}	{"buyer_name":"Ali","buyer_ntn":null,"total_amount":273.22}	10.83.4.10	25d0b7c5b7f46a96531c15c02db85621a7e1473162cb7d7a867ea2d9e384c4ae	2026-02-14 16:26:40
100	7	10	invoice_submitted	Invoice	15	\N	{"mode":"smart","compliance_score":55,"risk_level":"HIGH"}	10.83.7.9	ced7d24b96d637fa20df3c60bbb86f520f5fc375ca637abd209ffe7137c812c6	2026-02-14 16:26:53
101	7	10	invoice_fbr_failed	Invoice	15	\N	{"failure_type":"payload_error","mode":"sync"}	10.83.7.9	2a99f3290b269707ff726995579205d48979e4201567e9093976512e3690f7a6	2026-02-14 16:26:56
102	7	10	invoice_submitted	Invoice	15	\N	{"mode":"smart","compliance_score":55,"risk_level":"HIGH"}	10.83.6.39	7bb310f958f69ccf40e3d8394fdaa8d737ba2aebe46b4b05556cb721079827e9	2026-02-14 16:39:25
103	7	10	invoice_fbr_success	Invoice	15	\N	{"fbr_invoice_number":"3620291786117DIACOVLU269691","environment":"production","mode":"sync"}	10.83.6.39	ee5b591dcc2735f65bad7349b472de463d4fc0072c6e3615c190123a5df76b1e	2026-02-14 16:39:28
104	7	10	invoice_created	Invoice	16	\N	{"invoice_number":"3620291786117DI1771087236021","buyer_name":"walk in cutomer","total_amount":819.66,"document_type":"Sale Invoice"}	10.83.6.39	7f5051a53e2e544a17873af71164361fac1c66e61f058b90eb1adc4ec4895048	2026-02-14 16:40:36
105	7	10	invoice_submitted	Invoice	16	\N	{"mode":"smart","compliance_score":55,"risk_level":"HIGH"}	10.83.6.39	053cc5473a301687dc1675b9172a14b588137992b98f6b4769a1a514e628588f	2026-02-14 16:40:43
106	7	10	invoice_submitted	Invoice	16	\N	{"mode":"smart","compliance_score":55,"risk_level":"HIGH"}	10.83.6.39	649c12fde3ca1b03849bbc788f6952ae9afc9b00c029137de81467348a009fa7	2026-02-14 16:40:57
107	7	10	invoice_fbr_failed	Invoice	16	\N	{"failure_type":"payload_error","mode":"sync"}	10.83.6.39	01884d9bc65570a5c26acc08ec1b10b371dca99a47f2cf8caa6fec85f5b5bb2f	2026-02-14 16:41:01
108	7	10	invoice_created	Invoice	17	\N	{"invoice_number":"3620291786117DI1771087542134","buyer_name":"Naeem","total_amount":10.5,"document_type":"Sale Invoice"}	10.83.6.39	0c221765ac2bc5f2217fcd9c264716737a33a3751f7e3c4774771bb3b53d033d	2026-02-14 16:45:42
109	7	10	invoice_submitted	Invoice	17	\N	{"mode":"smart","compliance_score":55,"risk_level":"HIGH"}	10.83.1.3	e6ad664e14a9541226531591d30b8d64d64f06a83b6297747f688b52bfc00755	2026-02-14 16:45:59
110	7	10	invoice_fbr_failed	Invoice	17	\N	{"failure_type":"payload_error","mode":"sync"}	10.83.1.3	9e9c03a3a4927f483e17ac5990b369cda4a4ae3367f21b32d09dad87e3b7bf09	2026-02-14 16:46:02
111	7	10	invoice_submitted	Invoice	16	\N	{"mode":"smart","compliance_score":55,"risk_level":"HIGH"}	10.83.8.57	f738e3c1c06b88988985d538337d5c621efa065f1dd5fc0614dbde9dac430971	2026-02-14 16:55:38
112	7	10	invoice_fbr_failed	Invoice	16	\N	{"failure_type":"payload_error","mode":"sync"}	10.83.8.57	418e2c64e38fdac1cbd9edea2a0f7bc1c8f46ea2fc94e9f9fbb383a9695962ba	2026-02-14 16:55:42
113	7	10	invoice_submitted	Invoice	16	\N	{"mode":"smart","compliance_score":55,"risk_level":"HIGH"}	10.83.8.57	7d48a0f213ddc2d2f0aef978cce8034d6f6dca5f120ef3508bf9ef39b47247f5	2026-02-14 17:12:24
114	7	10	invoice_fbr_failed	Invoice	16	\N	{"failure_type":"payload_error","mode":"sync"}	10.83.8.57	016dc39fa51eb71efc34f424a499bdeeab297e4b9a0338a0a7451cfc9304e153	2026-02-14 17:12:28
115	7	10	invoice_submitted	Invoice	16	\N	{"mode":"smart","compliance_score":55,"risk_level":"HIGH"}	10.83.8.57	0b4895acf5e73644185ac965089a213d4047e3b70fcc7cc2ad615316e39ab636	2026-02-14 17:12:42
116	7	10	invoice_fbr_failed	Invoice	16	\N	{"failure_type":"payload_error","mode":"sync"}	10.83.8.57	ee062b54d756db158a8d5d238bbbce585a77589b6bd6b41dd70d86344f8be06a	2026-02-14 17:12:45
117	7	10	invoice_submitted	Invoice	16	\N	{"mode":"smart","compliance_score":55,"risk_level":"HIGH"}	10.83.11.49	5a7c3895ab18651c4137f5fd23ffadf05cd02e05e40b1b8d130ede458170fa8e	2026-02-14 17:41:11
118	7	10	invoice_submitted	Invoice	16	\N	{"mode":"smart","compliance_score":55,"risk_level":"HIGH"}	10.83.11.49	0ad2d57f03750e4d0f1dd67ccb59f48ff9d1e8c750886c4e8cf6c6e244377963	2026-02-14 17:41:41
119	7	10	invoice_submitted	Invoice	16	\N	{"mode":"smart","compliance_score":55,"risk_level":"HIGH"}	10.83.11.49	b82bd1374707be61ed42bf8aca641dbc1610f59e28bbefea7f1ee84b5d263845	2026-02-14 17:42:26
120	7	10	invoice_fbr_failed	Invoice	16	\N	{"failure_type":"payload_error","mode":"sync"}	10.83.11.49	0163562cf3be088ef35145cab83a4231930d3f3ca97e8a981ee97b4c44eca327	2026-02-14 17:42:30
121	7	10	invoice_submitted	Invoice	15	\N	{"mode":"smart","compliance_score":55,"risk_level":"HIGH"}	10.83.13.14	bc91fb9ba074484ef4b783db70fdc7a46c15c3638bff89f84402c8bebb465e61	2026-02-14 18:10:59
122	7	10	invoice_submitted	Invoice	16	\N	{"mode":"smart","compliance_score":55,"risk_level":"HIGH"}	10.83.6.39	964c8ddaa3b88d8c889293702c40ded9404698661e7116ba92c2aa0e0ca549e9	2026-02-14 18:11:59
123	7	10	invoice_fbr_failed	Invoice	16	\N	{"failure_type":"payload_error","mode":"sync"}	10.83.6.39	e381a427dae8f0a13eb0c40f7b3cce527654620a1c32979643aee1302c96949f	2026-02-14 18:12:02
124	7	10	invoice_submitted	Invoice	17	\N	{"mode":"smart","compliance_score":55,"risk_level":"HIGH"}	10.83.13.14	28e85a1ec9b3cb75bfa07796bd47630d0e65004e36a6e06d26d2091c5e806ec0	2026-02-15 14:22:45
125	7	10	invoice_fbr_failed	Invoice	17	\N	{"failure_type":"payload_error","mode":"sync"}	10.83.13.14	3cc966fd1aff8d666acb9eb0f868d52922e7451cca4667cb4d926a6be22e8ac6	2026-02-15 14:22:48
126	7	10	invoice_submitted	Invoice	18	\N	{"mode":"smart","compliance_score":55,"risk_level":"HIGH"}	10.83.7.79	b6bb78d7b3d3bc8c27ddac0dca3741deaebd3b5a33e032a356435df0cfffb668	2026-02-15 14:45:04
127	7	10	invoice_fbr_success	Invoice	18	\N	{"fbr_invoice_number":"3620291786117DIACPTQT003255","environment":"production","mode":"sync"}	10.83.7.79	32c1635541df051562f9497205c57176554bb4805d6c9d0d337b7f391d1d004e	2026-02-15 14:45:05
128	7	10	invoice_created	Invoice	19	\N	{"invoice_number":"3620291786117DI1771166839532","buyer_name":"Sajjad","total_amount":819.66,"document_type":"Sale Invoice"}	10.83.13.14	554245f0a974a56a3ed779821f7f3ff99a84700233d3eb63c8fa79b242fc97c6	2026-02-15 14:47:19
129	7	10	invoice_submitted	Invoice	19	\N	{"mode":"smart","compliance_score":55,"risk_level":"HIGH"}	10.83.12.54	e71cc450bcf2bb0e957284aca3ed36985aa55690df365f0671f5850838622002	2026-02-15 14:47:26
130	7	10	invoice_fbr_failed	Invoice	19	\N	{"failure_type":"validation_error","mode":"sync"}	10.83.12.54	f6b8bd80dac24431858d5cf58ccb3e721daced7d42eba70d5c8bfd0c92589696	2026-02-15 14:47:27
131	7	10	invoice_submitted	Invoice	19	\N	{"mode":"smart","compliance_score":55,"risk_level":"HIGH"}	10.83.6.62	53411943b2d65d090744b11a64b69de8720a90d23d254545a984080aaaded500	2026-02-15 14:49:20
132	7	10	invoice_fbr_failed	Invoice	19	\N	{"failure_type":"validation_error","mode":"sync"}	10.83.6.62	4c1b5613a2810703f89c4f95f35c8c6c10c93a70073443cf838ecfd5e49442ce	2026-02-15 14:49:21
133	7	10	invoice_submitted	Invoice	21	\N	{"mode":"smart","compliance_score":55,"risk_level":"HIGH"}	10.83.11.104	33ce82b33d38d26eba562c40a9732ef0ffcc167599b47ce92c9bf0e2a9f1c56c	2026-02-15 17:44:52
134	7	10	invoice_fbr_success	Invoice	21	\N	{"fbr_invoice_number":"3620291786117DIACPWRK747110","environment":"production","mode":"sync"}	10.83.11.104	07d9baa44ab57fa0af14c5b6cb0dc260982e3c7d6e2cf3765d5dc460336ce704	2026-02-15 17:44:54
135	7	10	invoice_submitted	Invoice	19	\N	{"mode":"smart","compliance_score":55,"risk_level":"HIGH"}	10.83.9.30	9465226cc8fa7e8fd85947f3af3dcd30d1460073a1e95e9990209f6474fcfbe6	2026-02-15 17:45:06
136	7	10	invoice_fbr_failed	Invoice	19	\N	{"error":"FBR submission blocked: previous success in fbr_logs. Invoice #19","mode":"sync"}	10.83.9.30	ce11ab5038f55f995e57a2cc0bea4b338bd73f4df1bcc3dc099fbc49c90d506b	2026-02-15 17:45:06
137	7	10	invoice_edited	Invoice	19	{"buyer_name":"Sajjad","buyer_ntn":null,"total_amount":"819.66"}	{"buyer_name":"Sajjad","buyer_ntn":null,"total_amount":1079.87}	10.83.3.32	6c71f4ba72374942fbc7c878521381491768df48b1f73e3a8db027e9e142f272	2026-02-15 18:26:20
138	7	10	invoice_submitted	Invoice	19	\N	{"mode":"smart","compliance_score":55,"risk_level":"HIGH"}	10.83.3.32	84ef7cf9d8f49baceec197c6782453db2bd85e1a94b680bfc1a5db109bbb12de	2026-02-15 18:26:25
139	7	10	invoice_fbr_failed	Invoice	19	\N	{"error":"FBR submission blocked: previous success in fbr_logs. Invoice #19","mode":"sync"}	10.83.3.32	d522b4d1c290ffb323fa7bc220229ec7d56a080a7f50f57c82e897c94bb6d0ab	2026-02-15 18:26:25
140	7	10	invoice_edited	Invoice	19	{"buyer_name":"Sajjad","buyer_ntn":null,"total_amount":"1079.87"}	{"buyer_name":"Sajjad","buyer_ntn":null,"total_amount":546.44}	10.83.3.32	b32f8e4d710e9018ab6a092221929828815ba5e4a5cade5a676fa99c0dd4898b	2026-02-15 18:27:21
141	7	10	invoice_submitted	Invoice	19	\N	{"mode":"smart","compliance_score":55,"risk_level":"HIGH"}	10.83.3.32	b49fda0e61add7f91e59493a1c862b4a8be38126906c343e300eda2329e77673	2026-02-15 18:27:27
142	7	10	invoice_fbr_failed	Invoice	19	\N	{"error":"FBR submission blocked: previous success in fbr_logs. Invoice #19","mode":"sync"}	10.83.3.32	1b38d98e4fac58220a9646dfcc08f3b37a32a600c28e623c163f2ce99b0e33aa	2026-02-15 18:27:27
143	7	10	invoice_created	Invoice	26	\N	{"invoice_number":"3620291786117DI1771221712284","buyer_name":"walk in cutomer","total_amount":2732.2,"document_type":"Sale Invoice"}	10.83.8.57	3845ecdb55ca230992118ffb53f67014865c8f0c9dd7ab5976585fdf34385132	2026-02-16 06:01:52
144	7	10	invoice_submitted	Invoice	26	\N	{"mode":"smart","compliance_score":55,"risk_level":"HIGH"}	10.83.3.32	387154f35d0b6ca0aa0287010286363bc8a45258c7d405f1848475a7a812719e	2026-02-16 06:01:56
145	7	10	invoice_fbr_success	Invoice	26	\N	{"fbr_invoice_number":"3620291786117DIACQLAN954426","environment":"production","mode":"sync"}	10.83.3.32	b6a2f23a3420d8cf7fc9bcf69194d1acf5a68ea02f0184324d2939feb31596f2	2026-02-16 06:01:58
146	7	10	invoice_created	Invoice	27	\N	{"invoice_number":"3620291786117DI1771246493957","buyer_name":"MALIK FAWAD","total_amount":1366.1,"document_type":"Sale Invoice"}	10.83.6.78	c7c0bc03e5f0d300c58f392fac679303854e7872d03e8c6429cfcfe119417a0a	2026-02-16 12:54:54
147	7	10	invoice_submitted	Invoice	27	\N	{"mode":"smart","compliance_score":55,"risk_level":"HIGH"}	10.83.9.39	abace8c075c9f0b1c05fb1ae2e7f8d49430cee1cdc07df8d9c3c238f44634139	2026-02-16 12:55:12
148	7	10	invoice_fbr_success	Invoice	27	\N	{"fbr_invoice_number":"3620291786117DIACQRBD942496","environment":"production","mode":"sync"}	10.83.9.39	f54ab318d03b39d83867ddf7daff88977c58e5f7ac0ead088b93c208bc4fad39	2026-02-16 12:55:14
149	7	10	invoice_created	Invoice	28	\N	{"invoice_number":"3620291786117DI1771318614634","buyer_name":"walk in cutomer","total_amount":1639.32,"document_type":"Sale Invoice"}	10.83.3.32	16b057890612d30d38b84f2f5af6a9ed31cbdc2d6244c39e391177279d99f8d5	2026-02-17 08:56:54
150	7	10	invoice_submitted	Invoice	28	\N	{"mode":"smart","compliance_score":55,"risk_level":"HIGH"}	10.83.7.125	c587ea47d114833c04bdc1770d7bf9eb9f0776e97df7c3de051038ede17ce5c6	2026-02-17 08:57:13
151	7	10	invoice_fbr_success	Invoice	28	\N	{"fbr_invoice_number":"3620291786117DIACRNFN740406","environment":"production","mode":"sync"}	10.83.7.125	2ab0d592722e4cd482df4c3c4d3c55c587de26af72024db0e913d0de0abe5231	2026-02-17 08:57:15
152	7	10	invoice_created	Invoice	29	\N	{"invoice_number":"3620291786117DI1771320389969","buyer_name":"jawad","total_amount":3327.6,"document_type":"Sale Invoice"}	10.83.8.57	102e8a9f1d164db8516e973bcc95eaddc5d9de3c9b17c518bc089758b2c9a68d	2026-02-17 09:26:29
153	7	10	invoice_submitted	Invoice	29	\N	{"mode":"smart","compliance_score":70,"risk_level":"MODERATE"}	10.83.4.6	4d575ecb361f80dd488a63be12a4632f591eaba797e42c92e01bce5ce2bfc297	2026-02-17 09:27:21
154	7	10	invoice_fbr_failed	Invoice	29	\N	{"failure_type":"validation_error","mode":"sync"}	10.83.4.6	5dc1271a8b268f1709e12f36f57276c828f2796a72016ea642407539133870a3	2026-02-17 09:27:23
155	7	10	invoice_created	Invoice	30	\N	{"invoice_number":"3620291786117DI1771321000267","buyer_name":"walk in cutomer","total_amount":273.22,"document_type":"Sale Invoice"}	10.83.8.57	2a7dd2df531980a8db6c23a9f8839a6d2de8e1c1c53b22d1fb6e64af7aea2e67	2026-02-17 09:36:40
156	7	10	invoice_submitted	Invoice	30	\N	{"mode":"smart","compliance_score":55,"risk_level":"HIGH"}	10.83.4.76	ac4ab9dd354dd2aca8dd08e2964abed7d3350c33803898d6d75dc49296b13b6c	2026-02-17 09:36:46
157	7	10	invoice_fbr_success	Invoice	30	\N	{"fbr_invoice_number":"3620291786117DIACROKV587270","environment":"production","mode":"sync"}	10.83.4.76	5d200c12ba8a7c9bed54edc9382cf36e674a4d98711473e4c5e1fbcb37b80de0	2026-02-17 09:36:48
158	7	10	invoice_deleted	Invoice	29	\N	{"invoice_number":"3620291786117DI1771320389969","buyer_name":"jawad","total_amount":"3327.60","user":"ZIA UR REHMAN"}	10.83.3.32	b6d5b303872d0f97e35d41c5b8df1b4a0e1c2a4a6bedb859f60d5584db61080d	2026-02-17 09:42:19
159	\N	1	company_plan_changed	Company	7	\N	{"new_plan_id":"8"}	10.83.14.40	2050711a233bd37fbf740f51dd709a0bae0a40a58a24a43c7685c823b3335bff	2026-02-21 17:47:28
\.


--
-- Data for Name: branches; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.branches (id, company_id, name, address, is_head_office, created_at, updated_at, province) FROM stdin;
\.


--
-- Data for Name: cache; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.cache (key, value, expiration) FROM stdin;
\.


--
-- Data for Name: cache_locks; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.cache_locks (key, owner, expiration) FROM stdin;
\.


--
-- Data for Name: companies; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.companies (id, name, ntn, email, phone, address, fbr_token, created_at, updated_at, compliance_score, token_expires_at, fbr_environment, fbr_sandbox_token, fbr_production_token, fbr_registration_no, fbr_business_name, suspended_at, company_status, token_expiry_date, last_successful_submission, fbr_connection_status, is_internal_account, onboarding_completed, standard_tax_rate, sector_type, province, invoice_number_prefix, next_invoice_number, fbr_sandbox_url, fbr_production_url, cnic, business_activity, owner_name, invoice_limit_override, user_limit_override, branch_limit_override, registration_no, mobile, city, website, inventory_enabled, pra_reporting_enabled, pra_environment, pra_pos_id, pra_production_token, receipt_printer_size, status, franchise_id, logo_path, pra_access_code, confidential_pin, next_local_invoice_number) FROM stdin;
3	Test Corp Pvt Ltd	9999999-1	testowner@example.com	\N	\N	\N	2026-02-11 12:32:48	2026-02-11 14:17:25	100	\N	sandbox	\N	\N	\N	\N	\N	active	\N	\N	unknown	f	t	18.00	Retail	\N	\N	1	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	f	f	sandbox	\N	\N	80mm	approved	\N	\N	\N	\N	1
1	TaxNest Solutions Ltd	1234567-8	contact@taxnest.com	0300-1234567	I-10 Industrial Area, Islamabad	dummy-fbr-token-123	2026-02-11 10:22:40	2026-02-11 14:17:25	85	\N	sandbox	eyJpdiI6ImsydWt0bWJubk16ZGs4NTArRzlhMnc9PSIsInZhbHVlIjoiTUNSL2EyL05NZjNMUzM3R3NCdk9MdHJuWXlDRGhqM2hubk01ZnRrQ2Mybz0iLCJtYWMiOiIxMDY0Mjg0NWEzNjAzNzhmOGY4YjQ2MGFlZjQ0MjhhM2JkYmRlZTEwZTQ5ODQyZTFmMjk2ZmY1ZDYxYTE2MzcwIiwidGFnIjoiIn0=	\N	REG-12345	Test Trading	\N	active	\N	\N	unknown	f	t	18.00	Retail	\N	\N	1	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	f	f	sandbox	\N	\N	80mm	approved	\N	\N	\N	\N	1
4	New Test Corp	1111111-1	newtest@test.com	\N	\N	\N	2026-02-11 13:10:35	2026-02-11 14:17:25	100	\N	sandbox	\N	\N	\N	\N	\N	active	\N	\N	unknown	f	t	18.00	Retail	\N	\N	1	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	f	f	sandbox	\N	\N	80mm	approved	\N	\N	\N	\N	1
2	Demo Traders Pvt Ltd	9876543-2	info@demotraders.pk	0321-9876543	I-8 Markaz, Islamabad	demo-fbr-token-xyz	2026-02-11 11:46:17	2026-02-12 11:52:40	84	\N	sandbox	\N	\N	\N	\N	\N	active	\N	2026-02-12 11:52:40	unknown	t	t	18.00	Retail	\N	DEMOT	2	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	f	f	sandbox	\N	\N	80mm	approved	\N	\N	\N	\N	1
5	Sandbox Test Company	5555555-5	sandbox@test.com	0300-1234567	Office 123 Islamabad	\N	2026-02-13 05:03:24	2026-02-13 05:03:24	100	\N	sandbox	\N	\N	\N	\N	\N	active	\N	\N	unknown	f	f	18.00	Retail	\N	\N	1	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	f	f	sandbox	\N	\N	80mm	approved	\N	\N	\N	\N	1
6	FBR Testing Corp	8888888-8	fbrtest@testing.com	0321-9876543	Blue Area Office 45 Islamabad	\N	2026-02-13 05:03:52	2026-02-13 05:03:52	100	\N	sandbox	\N	\N	\N	\N	\N	active	\N	\N	unknown	f	f	18.00	Retail	\N	\N	1	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	\N	f	f	sandbox	\N	\N	80mm	approved	\N	\N	\N	\N	1
12	Test Trading Company	1234567890123	test@testtrading.pk	03111234567	Mall Road, Rawalpindi, Pakistan	\N	2026-03-06 10:51:53	2026-03-06 16:30:32	100	\N	sandbox	\N	\N	\N	\N	\N	pending	\N	\N	unknown	f	t	18.00	Retail	Punjab	\N	1	\N	\N	3520112345678	Retailer - General Store	Test Owner	\N	\N	\N	\N	\N	Rawalpindi	\N	f	f	sandbox	\N	\N	80mm	approved	\N	\N	\N	\N	1
7	ZIA CORPORATION	3638114-4	8612580zur@gmail.com	00923008612580	KHAIR PUR ROAD PULL FAREED ABAD KAHROR PAKKA, Lodhran Kahror Pakka	\N	2026-02-13 05:12:05	2026-03-06 08:34:08	100	\N	production	eyJpdiI6ImZ4N0hORUxHOXQ2Q3hEbnZQcWdJUFE9PSIsInZhbHVlIjoiQUdvYkNzR0V2eklHbzdXbWtTM2hEdkVvdTQ0MWhqL2dJQTdia1BXQkRwakRoSGRiSTZTN3oxdmFhMW5ycUIyWiIsIm1hYyI6ImI4ZWU0YWNhZGQ4Nzc5YzgwZDlhYzZjYTA4NWFhOWUxMmZjZDQyZGJjYmNhMDIwYjA2ZTE0ZDFlMmNmMjlmMmUiLCJ0YWciOiIifQ==	eyJpdiI6Ii82OTFZTzg4TUlpano1M3pBMEs4Ync9PSIsInZhbHVlIjoiNlBkSHBsKzdVS3k1MDlNSW95bHQ0Umo1Q3VrdTlZeDFlb0pielF4ZGRIYkg1RTJoSU84cDU2cXRycUw0eDFwbyIsIm1hYyI6ImMzYTE4ZjYyOGMwNjQwZmI5ZjNlN2Y0YmJmMWEwYmNhYTUxNDgzYjY3YzdjZGIzOWNiMjAyMDBmOTNlMTlhMTciLCJ0YWciOiIifQ==	3620291786117	ZIA CORPORATION	\N	active	\N	2026-02-17 09:36:48	green	f	t	18.00	Retail	\N	ZIACO	20	https://gw.fbr.gov.pk/di_data/v1/di/postinvoicedata_sb	https://gw.fbr.gov.pk/di_data/v1/di/postinvoicedata	3620291786117	Retailer - Wholesale and retail trade	ZIA UR REHMAN	-1	\N	\N	3620291786117	00923008612580	Kahror Pakka	\N	f	f	sandbox	\N	\N	80mm	approved	\N	\N	\N	\N	1
11	NestPOS Enterprise Store	0000000000000	pos@nestpos.pk	03001234567	Main Boulevard, Lahore, Pakistan	\N	2026-03-06 10:47:55	2026-03-09 09:33:51	100	\N	sandbox	\N	\N	\N	\N	\N	active	\N	\N	unknown	f	t	16.00	Retail	Punjab	\N	1	\N	\N	\N	\N	POS Admin	\N	\N	\N	\N	\N	Lahore	\N	t	t	sandbox	\N	\N	80mm	approved	\N	\N	\N	\N	1
13	MALIK CHICKEN BROAST	7408263-3	malikchickenbroast@taxnest.com	\N	OPPOSITE 15 POLIC STATION MULTAN ROAD	\N	2026-03-09 12:46:22	2026-03-24 09:23:16	100	\N	sandbox	\N	\N	\N	\N	\N	active	\N	\N	unknown	f	f	18.00	Retail	\N	\N	1	\N	\N	3620349863077	RESTAURANT	MOHAMMA RASHEED	\N	\N	\N	\N	\N	LODHRAN	\N	t	t	production	191963	\N	80mm	active	\N	company-logos/XCl0NrEnjpJtlwmHoMsJfLB2AW4jQBsXQNIhAgcB.png	F1AC5300	$2y$12$9V8uXpXvfX7kmGL91zkTx.g4aomt9D/dubZXI1ASA2e9WiVDTNWAm	1
\.


--
-- Data for Name: company_usage_stats; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.company_usage_stats (id, company_id, total_pos_transactions, total_sales_amount, active_terminals, active_users, inventory_items, last_activity_at, created_at, updated_at) FROM stdin;
1	3	0	0.00	0	1	0	2026-03-06 04:56:08	2026-03-06 04:49:49	2026-03-06 04:56:08
2	1	0	0.00	0	3	0	2026-03-06 04:56:08	2026-03-06 04:49:49	2026-03-06 04:56:08
3	4	0	0.00	0	1	0	2026-03-06 04:56:08	2026-03-06 04:49:49	2026-03-06 04:56:08
4	2	0	0.00	0	1	0	2026-03-06 04:56:08	2026-03-06 04:49:49	2026-03-06 04:56:08
5	5	0	0.00	0	1	0	2026-03-06 04:56:08	2026-03-06 04:49:49	2026-03-06 04:56:08
6	6	0	0.00	0	1	0	2026-03-06 04:56:08	2026-03-06 04:49:49	2026-03-06 04:56:08
7	7	0	0.00	0	1	0	2026-03-06 04:56:08	2026-03-06 04:49:49	2026-03-06 04:56:08
8	12	0	0.00	0	1	0	2026-03-06 16:30:32	2026-03-06 10:53:42	2026-03-06 16:30:32
\.


--
-- Data for Name: compliance_reports; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.compliance_reports (id, company_id, invoice_id, rule_flags, anomaly_flags, final_score, risk_level, created_at, updated_at, is_fbr_validated, pre_validation_flags) FROM stdin;
2	2	8	{"RATE_MISMATCH":false,"BUYER_RISK":true,"BANKING_RISK":false,"STRUCTURE_ERROR":true}	{"MOM_SPIKE":0,"TAX_DROP":0,"HS_SHIFT":false,"VALUE_TAX_ANOMALY":false,"risk_weight":0}	70	MODERATE	2026-02-12 07:06:11	2026-02-12 07:06:11	f	\N
3	2	8	{"RATE_MISMATCH":false,"BUYER_RISK":true,"BANKING_RISK":false,"STRUCTURE_ERROR":true}	{"MOM_SPIKE":0,"TAX_DROP":0,"HS_SHIFT":false,"VALUE_TAX_ANOMALY":false,"risk_weight":0}	70	MODERATE	2026-02-12 07:06:16	2026-02-12 07:06:16	f	\N
4	2	8	{"RATE_MISMATCH":false,"BUYER_RISK":true,"BANKING_RISK":false,"STRUCTURE_ERROR":true}	{"MOM_SPIKE":0,"TAX_DROP":0,"HS_SHIFT":false,"VALUE_TAX_ANOMALY":false,"risk_weight":0}	70	MODERATE	2026-02-12 08:50:20	2026-02-12 08:50:20	f	\N
5	7	9	{"RATE_MISMATCH":true,"BUYER_RISK":true,"BANKING_RISK":false,"STRUCTURE_ERROR":true}	{"MOM_SPIKE":0,"TAX_DROP":0,"HS_SHIFT":false,"VALUE_TAX_ANOMALY":false,"risk_weight":0}	55	HIGH	2026-02-13 05:31:23	2026-02-13 05:31:23	f	\N
6	7	9	{"RATE_MISMATCH":true,"BUYER_RISK":true,"BANKING_RISK":false,"STRUCTURE_ERROR":true}	{"MOM_SPIKE":0,"TAX_DROP":0,"HS_SHIFT":false,"VALUE_TAX_ANOMALY":false,"risk_weight":0}	55	HIGH	2026-02-13 05:31:31	2026-02-13 05:31:31	f	\N
7	7	9	{"RATE_MISMATCH":true,"BUYER_RISK":true,"BANKING_RISK":false,"STRUCTURE_ERROR":true}	{"MOM_SPIKE":0,"TAX_DROP":0,"HS_SHIFT":false,"VALUE_TAX_ANOMALY":false,"risk_weight":0}	55	HIGH	2026-02-13 07:35:18	2026-02-13 07:35:18	f	\N
8	7	9	{"RATE_MISMATCH":true,"BUYER_RISK":true,"BANKING_RISK":false,"STRUCTURE_ERROR":true}	{"MOM_SPIKE":0,"TAX_DROP":0,"HS_SHIFT":false,"VALUE_TAX_ANOMALY":false,"risk_weight":0}	55	HIGH	2026-02-13 09:48:49	2026-02-13 09:48:49	f	\N
9	7	9	{"RATE_MISMATCH":true,"BUYER_RISK":true,"BANKING_RISK":false,"STRUCTURE_ERROR":true}	{"MOM_SPIKE":0,"TAX_DROP":0,"HS_SHIFT":false,"VALUE_TAX_ANOMALY":false,"risk_weight":0}	55	HIGH	2026-02-13 09:52:50	2026-02-13 09:52:50	f	\N
10	7	9	{"RATE_MISMATCH":true,"BUYER_RISK":true,"BANKING_RISK":false,"STRUCTURE_ERROR":true}	{"MOM_SPIKE":0,"TAX_DROP":0,"HS_SHIFT":false,"VALUE_TAX_ANOMALY":false,"risk_weight":0}	55	HIGH	2026-02-13 09:56:36	2026-02-13 09:56:36	f	\N
11	7	10	{"RATE_MISMATCH":true,"BUYER_RISK":true,"BANKING_RISK":false,"STRUCTURE_ERROR":true}	{"MOM_SPIKE":0,"TAX_DROP":0,"HS_SHIFT":false,"VALUE_TAX_ANOMALY":false,"risk_weight":0}	55	HIGH	2026-02-14 06:23:04	2026-02-14 06:23:04	f	\N
12	7	10	{"RATE_MISMATCH":true,"BUYER_RISK":true,"BANKING_RISK":false,"STRUCTURE_ERROR":true}	{"MOM_SPIKE":0,"TAX_DROP":0,"HS_SHIFT":false,"VALUE_TAX_ANOMALY":false,"risk_weight":0}	55	HIGH	2026-02-14 06:23:37	2026-02-14 06:23:37	f	\N
13	7	11	{"RATE_MISMATCH":true,"BUYER_RISK":true,"BANKING_RISK":false,"STRUCTURE_ERROR":true}	{"MOM_SPIKE":0,"TAX_DROP":0,"HS_SHIFT":false,"VALUE_TAX_ANOMALY":false,"risk_weight":0}	55	HIGH	2026-02-14 08:08:53	2026-02-14 08:08:53	f	\N
14	7	11	{"RATE_MISMATCH":true,"BUYER_RISK":true,"BANKING_RISK":false,"STRUCTURE_ERROR":true}	{"MOM_SPIKE":0,"TAX_DROP":0,"HS_SHIFT":false,"VALUE_TAX_ANOMALY":false,"risk_weight":0}	55	HIGH	2026-02-14 09:37:10	2026-02-14 09:37:10	f	\N
15	7	12	{"RATE_MISMATCH":true,"BUYER_RISK":true,"BANKING_RISK":false,"STRUCTURE_ERROR":true}	{"MOM_SPIKE":0,"TAX_DROP":0,"HS_SHIFT":false,"VALUE_TAX_ANOMALY":false,"risk_weight":0}	55	HIGH	2026-02-14 09:54:59	2026-02-14 09:54:59	f	\N
16	7	11	{"RATE_MISMATCH":true,"BUYER_RISK":true,"BANKING_RISK":false,"STRUCTURE_ERROR":true}	{"MOM_SPIKE":0,"TAX_DROP":0,"HS_SHIFT":false,"VALUE_TAX_ANOMALY":false,"risk_weight":0}	55	HIGH	2026-02-14 10:25:06	2026-02-14 10:25:06	f	\N
17	7	11	{"RATE_MISMATCH":true,"BUYER_RISK":true,"BANKING_RISK":false,"STRUCTURE_ERROR":true}	{"MOM_SPIKE":0,"TAX_DROP":0,"HS_SHIFT":false,"VALUE_TAX_ANOMALY":false,"risk_weight":0}	55	HIGH	2026-02-14 10:25:15	2026-02-14 10:25:15	f	\N
18	7	13	{"RATE_MISMATCH":true,"BUYER_RISK":true,"BANKING_RISK":false,"STRUCTURE_ERROR":true}	{"MOM_SPIKE":0,"TAX_DROP":0,"HS_SHIFT":false,"VALUE_TAX_ANOMALY":false,"risk_weight":0}	55	HIGH	2026-02-14 12:50:23	2026-02-14 12:50:23	f	\N
19	7	13	{"RATE_MISMATCH":true,"BUYER_RISK":true,"BANKING_RISK":false,"STRUCTURE_ERROR":true}	{"MOM_SPIKE":0,"TAX_DROP":0,"HS_SHIFT":false,"VALUE_TAX_ANOMALY":false,"risk_weight":0}	55	HIGH	2026-02-14 12:50:25	2026-02-14 12:50:25	f	\N
20	7	13	{"RATE_MISMATCH":true,"BUYER_RISK":true,"BANKING_RISK":false,"STRUCTURE_ERROR":true}	{"MOM_SPIKE":0,"TAX_DROP":0,"HS_SHIFT":false,"VALUE_TAX_ANOMALY":false,"risk_weight":0}	55	HIGH	2026-02-14 13:06:02	2026-02-14 13:06:02	f	\N
21	7	13	{"RATE_MISMATCH":true,"BUYER_RISK":true,"BANKING_RISK":false,"STRUCTURE_ERROR":true}	{"MOM_SPIKE":0,"TAX_DROP":0,"HS_SHIFT":false,"VALUE_TAX_ANOMALY":false,"risk_weight":0}	55	HIGH	2026-02-14 13:08:26	2026-02-14 13:08:26	f	\N
22	7	13	{"RATE_MISMATCH":true,"BUYER_RISK":true,"BANKING_RISK":false,"STRUCTURE_ERROR":true}	{"MOM_SPIKE":0,"TAX_DROP":0,"HS_SHIFT":false,"VALUE_TAX_ANOMALY":false,"risk_weight":0}	55	HIGH	2026-02-14 13:46:34	2026-02-14 13:46:34	f	\N
23	7	14	{"RATE_MISMATCH":true,"BUYER_RISK":true,"BANKING_RISK":false,"STRUCTURE_ERROR":true}	{"MOM_SPIKE":0,"TAX_DROP":0,"HS_SHIFT":false,"VALUE_TAX_ANOMALY":false,"risk_weight":0}	55	HIGH	2026-02-14 13:48:10	2026-02-14 13:48:10	f	\N
24	7	14	{"RATE_MISMATCH":true,"BUYER_RISK":true,"BANKING_RISK":false,"STRUCTURE_ERROR":true}	{"MOM_SPIKE":0,"TAX_DROP":0,"HS_SHIFT":false,"VALUE_TAX_ANOMALY":false,"risk_weight":0}	55	HIGH	2026-02-14 13:48:12	2026-02-14 13:48:12	f	\N
25	7	14	{"RATE_MISMATCH":true,"BUYER_RISK":true,"BANKING_RISK":false,"STRUCTURE_ERROR":true}	{"MOM_SPIKE":0,"TAX_DROP":0,"HS_SHIFT":false,"VALUE_TAX_ANOMALY":false,"risk_weight":0}	55	HIGH	2026-02-14 13:52:24	2026-02-14 13:52:24	f	\N
26	7	14	{"RATE_MISMATCH":true,"BUYER_RISK":true,"BANKING_RISK":false,"STRUCTURE_ERROR":true}	{"MOM_SPIKE":0,"TAX_DROP":0,"HS_SHIFT":false,"VALUE_TAX_ANOMALY":false,"risk_weight":0}	55	HIGH	2026-02-14 13:55:54	2026-02-14 13:55:54	f	\N
27	7	13	{"RATE_MISMATCH":true,"BUYER_RISK":true,"BANKING_RISK":false,"STRUCTURE_ERROR":true}	{"MOM_SPIKE":0,"TAX_DROP":0,"HS_SHIFT":false,"VALUE_TAX_ANOMALY":false,"risk_weight":0}	55	HIGH	2026-02-14 13:56:55	2026-02-14 13:56:55	f	\N
28	7	13	{"RATE_MISMATCH":true,"BUYER_RISK":true,"BANKING_RISK":false,"STRUCTURE_ERROR":true}	{"MOM_SPIKE":0,"TAX_DROP":0,"HS_SHIFT":false,"VALUE_TAX_ANOMALY":false,"risk_weight":0}	55	HIGH	2026-02-14 13:57:02	2026-02-14 13:57:02	f	\N
29	7	13	{"RATE_MISMATCH":true,"BUYER_RISK":true,"BANKING_RISK":false,"STRUCTURE_ERROR":true}	{"MOM_SPIKE":0,"TAX_DROP":0,"HS_SHIFT":false,"VALUE_TAX_ANOMALY":false,"risk_weight":0}	55	HIGH	2026-02-14 13:57:11	2026-02-14 13:57:11	f	\N
30	7	13	{"RATE_MISMATCH":true,"BUYER_RISK":true,"BANKING_RISK":false,"STRUCTURE_ERROR":true}	{"MOM_SPIKE":0,"TAX_DROP":0,"HS_SHIFT":false,"VALUE_TAX_ANOMALY":false,"risk_weight":0}	55	HIGH	2026-02-14 13:59:41	2026-02-14 13:59:41	f	\N
31	7	13	{"RATE_MISMATCH":true,"BUYER_RISK":true,"BANKING_RISK":false,"STRUCTURE_ERROR":true}	{"MOM_SPIKE":0,"TAX_DROP":0,"HS_SHIFT":false,"VALUE_TAX_ANOMALY":false,"risk_weight":0}	55	HIGH	2026-02-14 13:59:53	2026-02-14 13:59:53	f	\N
1	2	\N	{"RATE_MISMATCH":false,"BUYER_RISK":false,"BANKING_RISK":false,"STRUCTURE_ERROR":false}	{"MOM_SPIKE":0,"TAX_DROP":0,"HS_SHIFT":false,"VALUE_TAX_ANOMALY":false,"risk_weight":0}	100	LOW	2026-02-11 12:24:16	2026-02-11 12:24:16	f	\N
32	7	\N	{"RATE_MISMATCH":true,"BUYER_RISK":true,"BANKING_RISK":false,"STRUCTURE_ERROR":true}	{"MOM_SPIKE":0,"TAX_DROP":0,"HS_SHIFT":false,"VALUE_TAX_ANOMALY":false,"risk_weight":0}	55	HIGH	2026-02-14 14:04:42	2026-02-14 14:04:42	f	\N
33	7	\N	{"RATE_MISMATCH":true,"BUYER_RISK":true,"BANKING_RISK":false,"STRUCTURE_ERROR":true}	{"MOM_SPIKE":0,"TAX_DROP":0,"HS_SHIFT":false,"VALUE_TAX_ANOMALY":false,"risk_weight":0}	55	HIGH	2026-02-14 14:05:27	2026-02-14 14:05:27	f	\N
34	7	\N	{"RATE_MISMATCH":true,"BUYER_RISK":true,"BANKING_RISK":false,"STRUCTURE_ERROR":true}	{"MOM_SPIKE":0,"TAX_DROP":0,"HS_SHIFT":false,"VALUE_TAX_ANOMALY":false,"risk_weight":0}	55	HIGH	2026-02-14 14:05:38	2026-02-14 14:05:38	f	\N
35	7	\N	{"RATE_MISMATCH":true,"BUYER_RISK":true,"BANKING_RISK":false,"STRUCTURE_ERROR":true}	{"MOM_SPIKE":0,"TAX_DROP":0,"HS_SHIFT":false,"VALUE_TAX_ANOMALY":false,"risk_weight":0}	55	HIGH	2026-02-14 14:09:01	2026-02-14 14:09:01	f	\N
36	7	\N	{"RATE_MISMATCH":true,"BUYER_RISK":true,"BANKING_RISK":false,"STRUCTURE_ERROR":true}	{"MOM_SPIKE":0,"TAX_DROP":0,"HS_SHIFT":false,"VALUE_TAX_ANOMALY":false,"risk_weight":0}	55	HIGH	2026-02-14 14:09:09	2026-02-14 14:09:09	f	\N
37	7	\N	{"RATE_MISMATCH":true,"BUYER_RISK":true,"BANKING_RISK":false,"STRUCTURE_ERROR":true}	{"MOM_SPIKE":0,"TAX_DROP":0,"HS_SHIFT":false,"VALUE_TAX_ANOMALY":false,"risk_weight":0}	55	HIGH	2026-02-14 14:09:55	2026-02-14 14:09:55	f	\N
38	7	\N	{"RATE_MISMATCH":true,"BUYER_RISK":true,"BANKING_RISK":false,"STRUCTURE_ERROR":true}	{"MOM_SPIKE":0,"TAX_DROP":0,"HS_SHIFT":false,"VALUE_TAX_ANOMALY":false,"risk_weight":0}	55	HIGH	2026-02-14 14:09:59	2026-02-14 14:09:59	f	\N
39	7	\N	{"RATE_MISMATCH":true,"BUYER_RISK":true,"BANKING_RISK":false,"STRUCTURE_ERROR":true}	{"MOM_SPIKE":0,"TAX_DROP":0,"HS_SHIFT":false,"VALUE_TAX_ANOMALY":false,"risk_weight":0}	55	HIGH	2026-02-14 14:17:25	2026-02-14 14:17:25	f	\N
40	7	\N	{"RATE_MISMATCH":true,"BUYER_RISK":true,"BANKING_RISK":false,"STRUCTURE_ERROR":true}	{"MOM_SPIKE":0,"TAX_DROP":0,"HS_SHIFT":false,"VALUE_TAX_ANOMALY":false,"risk_weight":0}	55	HIGH	2026-02-14 14:26:09	2026-02-14 14:26:09	f	\N
41	7	\N	{"RATE_MISMATCH":true,"BUYER_RISK":true,"BANKING_RISK":false,"STRUCTURE_ERROR":true}	{"MOM_SPIKE":0,"TAX_DROP":0,"HS_SHIFT":false,"VALUE_TAX_ANOMALY":false,"risk_weight":0}	55	HIGH	2026-02-14 14:26:17	2026-02-14 14:26:17	f	\N
42	7	\N	{"RATE_MISMATCH":true,"BUYER_RISK":true,"BANKING_RISK":false,"STRUCTURE_ERROR":true}	{"MOM_SPIKE":0,"TAX_DROP":0,"HS_SHIFT":false,"VALUE_TAX_ANOMALY":false,"risk_weight":0}	55	HIGH	2026-02-14 14:40:08	2026-02-14 14:40:08	f	\N
43	7	\N	{"RATE_MISMATCH":true,"BUYER_RISK":true,"BANKING_RISK":false,"STRUCTURE_ERROR":true}	{"MOM_SPIKE":0,"TAX_DROP":0,"HS_SHIFT":false,"VALUE_TAX_ANOMALY":false,"risk_weight":0}	55	HIGH	2026-02-14 15:11:53	2026-02-14 15:11:53	f	\N
44	7	\N	{"RATE_MISMATCH":true,"BUYER_RISK":true,"BANKING_RISK":false,"STRUCTURE_ERROR":true}	{"MOM_SPIKE":0,"TAX_DROP":0,"HS_SHIFT":false,"VALUE_TAX_ANOMALY":false,"risk_weight":0}	55	HIGH	2026-02-14 15:11:57	2026-02-14 15:11:57	f	\N
45	7	\N	{"RATE_MISMATCH":true,"BUYER_RISK":true,"BANKING_RISK":false,"STRUCTURE_ERROR":true}	{"MOM_SPIKE":0,"TAX_DROP":0,"HS_SHIFT":false,"VALUE_TAX_ANOMALY":false,"risk_weight":0}	55	HIGH	2026-02-14 15:21:19	2026-02-14 15:21:19	f	\N
46	7	\N	{"RATE_MISMATCH":true,"BUYER_RISK":true,"BANKING_RISK":false,"STRUCTURE_ERROR":true}	{"MOM_SPIKE":0,"TAX_DROP":0,"HS_SHIFT":false,"VALUE_TAX_ANOMALY":false,"risk_weight":0}	55	HIGH	2026-02-14 15:21:25	2026-02-14 15:21:25	f	\N
47	7	\N	{"RATE_MISMATCH":true,"BUYER_RISK":true,"BANKING_RISK":false,"STRUCTURE_ERROR":true}	{"MOM_SPIKE":0,"TAX_DROP":0,"HS_SHIFT":false,"VALUE_TAX_ANOMALY":false,"risk_weight":0}	55	HIGH	2026-02-14 15:32:14	2026-02-14 15:32:14	f	\N
48	7	\N	{"RATE_MISMATCH":true,"BUYER_RISK":true,"BANKING_RISK":false,"STRUCTURE_ERROR":true}	{"MOM_SPIKE":0,"TAX_DROP":0,"HS_SHIFT":false,"VALUE_TAX_ANOMALY":false,"risk_weight":0}	55	HIGH	2026-02-14 15:33:04	2026-02-14 15:33:04	f	\N
49	7	\N	{"RATE_MISMATCH":true,"BUYER_RISK":true,"BANKING_RISK":false,"STRUCTURE_ERROR":true}	{"MOM_SPIKE":0,"TAX_DROP":0,"HS_SHIFT":false,"VALUE_TAX_ANOMALY":false,"risk_weight":0}	55	HIGH	2026-02-14 15:39:02	2026-02-14 15:39:02	f	\N
50	7	\N	{"RATE_MISMATCH":true,"BUYER_RISK":true,"BANKING_RISK":false,"STRUCTURE_ERROR":true}	{"MOM_SPIKE":0,"TAX_DROP":0,"HS_SHIFT":false,"VALUE_TAX_ANOMALY":false,"risk_weight":0}	55	HIGH	2026-02-14 15:41:22	2026-02-14 15:41:22	f	\N
51	7	\N	{"RATE_MISMATCH":true,"BUYER_RISK":true,"BANKING_RISK":false,"STRUCTURE_ERROR":true}	{"MOM_SPIKE":0,"TAX_DROP":0,"HS_SHIFT":false,"VALUE_TAX_ANOMALY":false,"risk_weight":0}	55	HIGH	2026-02-14 15:59:07	2026-02-14 15:59:07	f	\N
52	7	\N	{"RATE_MISMATCH":true,"BUYER_RISK":true,"BANKING_RISK":false,"STRUCTURE_ERROR":true}	{"MOM_SPIKE":0,"TAX_DROP":0,"HS_SHIFT":false,"VALUE_TAX_ANOMALY":false,"risk_weight":0}	55	HIGH	2026-02-14 16:00:17	2026-02-14 16:00:17	f	\N
53	7	\N	{"RATE_MISMATCH":true,"BUYER_RISK":true,"BANKING_RISK":false,"STRUCTURE_ERROR":true}	{"MOM_SPIKE":0,"TAX_DROP":0,"HS_SHIFT":false,"VALUE_TAX_ANOMALY":false,"risk_weight":0}	55	HIGH	2026-02-14 16:02:17	2026-02-14 16:02:17	f	\N
54	7	\N	{"RATE_MISMATCH":true,"BUYER_RISK":true,"BANKING_RISK":false,"STRUCTURE_ERROR":true}	{"MOM_SPIKE":0,"TAX_DROP":0,"HS_SHIFT":false,"VALUE_TAX_ANOMALY":false,"risk_weight":0}	55	HIGH	2026-02-14 16:06:11	2026-02-14 16:06:11	f	\N
55	7	\N	{"RATE_MISMATCH":true,"BUYER_RISK":true,"BANKING_RISK":false,"STRUCTURE_ERROR":true}	{"MOM_SPIKE":0,"TAX_DROP":0,"HS_SHIFT":false,"VALUE_TAX_ANOMALY":false,"risk_weight":0}	55	HIGH	2026-02-14 16:16:40	2026-02-14 16:16:40	f	\N
56	7	\N	{"RATE_MISMATCH":true,"BUYER_RISK":true,"BANKING_RISK":false,"STRUCTURE_ERROR":true}	{"MOM_SPIKE":0,"TAX_DROP":0,"HS_SHIFT":false,"VALUE_TAX_ANOMALY":false,"risk_weight":0}	55	HIGH	2026-02-14 16:23:34	2026-02-14 16:23:34	f	\N
57	7	\N	{"RATE_MISMATCH":true,"BUYER_RISK":true,"BANKING_RISK":false,"STRUCTURE_ERROR":true}	{"MOM_SPIKE":0,"TAX_DROP":0,"HS_SHIFT":false,"VALUE_TAX_ANOMALY":false,"risk_weight":0}	55	HIGH	2026-02-14 16:26:42	2026-02-14 16:26:42	f	\N
58	7	\N	{"RATE_MISMATCH":true,"BUYER_RISK":true,"BANKING_RISK":false,"STRUCTURE_ERROR":true}	{"MOM_SPIKE":0,"TAX_DROP":0,"HS_SHIFT":false,"VALUE_TAX_ANOMALY":false,"risk_weight":0}	55	HIGH	2026-02-14 16:26:53	2026-02-14 16:26:53	f	\N
59	7	\N	{"RATE_MISMATCH":true,"BUYER_RISK":true,"BANKING_RISK":false,"STRUCTURE_ERROR":true}	{"MOM_SPIKE":0,"TAX_DROP":0,"HS_SHIFT":false,"VALUE_TAX_ANOMALY":false,"risk_weight":0}	55	HIGH	2026-02-14 16:39:25	2026-02-14 16:39:25	f	\N
71	7	\N	{"RATE_MISMATCH":true,"BUYER_RISK":true,"BANKING_RISK":false,"STRUCTURE_ERROR":true}	{"MOM_SPIKE":0,"TAX_DROP":0,"HS_SHIFT":false,"VALUE_TAX_ANOMALY":false,"risk_weight":0}	55	HIGH	2026-02-14 18:10:59	2026-02-14 18:10:59	f	\N
60	7	\N	{"RATE_MISMATCH":true,"BUYER_RISK":true,"BANKING_RISK":false,"STRUCTURE_ERROR":true}	{"MOM_SPIKE":0,"TAX_DROP":0,"HS_SHIFT":false,"VALUE_TAX_ANOMALY":false,"risk_weight":0}	55	HIGH	2026-02-14 16:40:38	2026-02-14 16:40:38	f	\N
61	7	\N	{"RATE_MISMATCH":true,"BUYER_RISK":true,"BANKING_RISK":false,"STRUCTURE_ERROR":true}	{"MOM_SPIKE":0,"TAX_DROP":0,"HS_SHIFT":false,"VALUE_TAX_ANOMALY":false,"risk_weight":0}	55	HIGH	2026-02-14 16:40:43	2026-02-14 16:40:43	f	\N
62	7	\N	{"RATE_MISMATCH":true,"BUYER_RISK":true,"BANKING_RISK":false,"STRUCTURE_ERROR":true}	{"MOM_SPIKE":0,"TAX_DROP":0,"HS_SHIFT":false,"VALUE_TAX_ANOMALY":false,"risk_weight":0}	55	HIGH	2026-02-14 16:40:57	2026-02-14 16:40:57	f	\N
65	7	\N	{"RATE_MISMATCH":true,"BUYER_RISK":true,"BANKING_RISK":false,"STRUCTURE_ERROR":true}	{"MOM_SPIKE":0,"TAX_DROP":0,"HS_SHIFT":false,"VALUE_TAX_ANOMALY":false,"risk_weight":0}	55	HIGH	2026-02-14 16:55:37	2026-02-14 16:55:37	f	\N
66	7	\N	{"RATE_MISMATCH":true,"BUYER_RISK":true,"BANKING_RISK":false,"STRUCTURE_ERROR":true}	{"MOM_SPIKE":0,"TAX_DROP":0,"HS_SHIFT":false,"VALUE_TAX_ANOMALY":false,"risk_weight":0}	55	HIGH	2026-02-14 17:12:24	2026-02-14 17:12:24	f	\N
67	7	\N	{"RATE_MISMATCH":true,"BUYER_RISK":true,"BANKING_RISK":false,"STRUCTURE_ERROR":true}	{"MOM_SPIKE":0,"TAX_DROP":0,"HS_SHIFT":false,"VALUE_TAX_ANOMALY":false,"risk_weight":0}	55	HIGH	2026-02-14 17:12:42	2026-02-14 17:12:42	f	\N
68	7	\N	{"RATE_MISMATCH":true,"BUYER_RISK":true,"BANKING_RISK":false,"STRUCTURE_ERROR":true}	{"MOM_SPIKE":0,"TAX_DROP":0,"HS_SHIFT":false,"VALUE_TAX_ANOMALY":false,"risk_weight":0}	55	HIGH	2026-02-14 17:41:11	2026-02-14 17:41:11	f	\N
69	7	\N	{"RATE_MISMATCH":true,"BUYER_RISK":true,"BANKING_RISK":false,"STRUCTURE_ERROR":true}	{"MOM_SPIKE":0,"TAX_DROP":0,"HS_SHIFT":false,"VALUE_TAX_ANOMALY":false,"risk_weight":0}	55	HIGH	2026-02-14 17:41:41	2026-02-14 17:41:41	f	\N
70	7	\N	{"RATE_MISMATCH":true,"BUYER_RISK":true,"BANKING_RISK":false,"STRUCTURE_ERROR":true}	{"MOM_SPIKE":0,"TAX_DROP":0,"HS_SHIFT":false,"VALUE_TAX_ANOMALY":false,"risk_weight":0}	55	HIGH	2026-02-14 17:42:26	2026-02-14 17:42:26	f	\N
72	7	\N	{"RATE_MISMATCH":true,"BUYER_RISK":true,"BANKING_RISK":false,"STRUCTURE_ERROR":true}	{"MOM_SPIKE":0,"TAX_DROP":0,"HS_SHIFT":false,"VALUE_TAX_ANOMALY":false,"risk_weight":0}	55	HIGH	2026-02-14 18:11:59	2026-02-14 18:11:59	f	\N
63	7	\N	{"RATE_MISMATCH":true,"BUYER_RISK":true,"BANKING_RISK":false,"STRUCTURE_ERROR":true}	{"MOM_SPIKE":0,"TAX_DROP":0,"HS_SHIFT":false,"VALUE_TAX_ANOMALY":false,"risk_weight":0}	55	HIGH	2026-02-14 16:45:43	2026-02-14 16:45:43	f	\N
64	7	\N	{"RATE_MISMATCH":true,"BUYER_RISK":true,"BANKING_RISK":false,"STRUCTURE_ERROR":true}	{"MOM_SPIKE":0,"TAX_DROP":0,"HS_SHIFT":false,"VALUE_TAX_ANOMALY":false,"risk_weight":0}	55	HIGH	2026-02-14 16:45:59	2026-02-14 16:45:59	f	\N
73	7	\N	{"RATE_MISMATCH":true,"BUYER_RISK":true,"BANKING_RISK":false,"STRUCTURE_ERROR":true}	{"MOM_SPIKE":0,"TAX_DROP":0,"HS_SHIFT":false,"VALUE_TAX_ANOMALY":false,"risk_weight":0}	55	HIGH	2026-02-15 14:22:45	2026-02-15 14:22:45	f	\N
74	7	18	{"RATE_MISMATCH":true,"BUYER_RISK":true,"BANKING_RISK":false,"STRUCTURE_ERROR":true}	{"MOM_SPIKE":0,"TAX_DROP":0,"HS_SHIFT":false,"VALUE_TAX_ANOMALY":false,"risk_weight":0}	55	HIGH	2026-02-15 14:45:04	2026-02-15 14:45:04	f	\N
75	7	19	{"RATE_MISMATCH":true,"BUYER_RISK":true,"BANKING_RISK":false,"STRUCTURE_ERROR":true}	{"MOM_SPIKE":0,"TAX_DROP":0,"HS_SHIFT":false,"VALUE_TAX_ANOMALY":false,"risk_weight":0}	55	HIGH	2026-02-15 14:47:19	2026-02-15 14:47:19	f	\N
76	7	19	{"RATE_MISMATCH":true,"BUYER_RISK":true,"BANKING_RISK":false,"STRUCTURE_ERROR":true}	{"MOM_SPIKE":0,"TAX_DROP":0,"HS_SHIFT":false,"VALUE_TAX_ANOMALY":false,"risk_weight":0}	55	HIGH	2026-02-15 14:47:26	2026-02-15 14:47:26	f	\N
77	7	19	{"RATE_MISMATCH":true,"BUYER_RISK":true,"BANKING_RISK":false,"STRUCTURE_ERROR":true}	{"MOM_SPIKE":0,"TAX_DROP":0,"HS_SHIFT":false,"VALUE_TAX_ANOMALY":false,"risk_weight":0}	55	HIGH	2026-02-15 14:49:20	2026-02-15 14:49:20	f	\N
78	7	21	{"RATE_MISMATCH":true,"BUYER_RISK":true,"BANKING_RISK":false,"STRUCTURE_ERROR":true}	{"MOM_SPIKE":0,"TAX_DROP":0,"HS_SHIFT":false,"VALUE_TAX_ANOMALY":false,"risk_weight":0}	55	HIGH	2026-02-15 17:44:52	2026-02-15 17:44:52	f	\N
79	7	19	{"RATE_MISMATCH":true,"BUYER_RISK":true,"BANKING_RISK":false,"STRUCTURE_ERROR":true}	{"MOM_SPIKE":0,"TAX_DROP":0,"HS_SHIFT":false,"VALUE_TAX_ANOMALY":false,"risk_weight":0}	55	HIGH	2026-02-15 17:45:06	2026-02-15 17:45:06	f	\N
80	7	19	{"RATE_MISMATCH":true,"BUYER_RISK":true,"BANKING_RISK":false,"STRUCTURE_ERROR":true}	{"MOM_SPIKE":0,"TAX_DROP":0,"HS_SHIFT":false,"VALUE_TAX_ANOMALY":false,"risk_weight":0}	55	HIGH	2026-02-15 18:25:51	2026-02-15 18:25:51	f	\N
81	7	19	{"RATE_MISMATCH":true,"BUYER_RISK":true,"BANKING_RISK":false,"STRUCTURE_ERROR":true}	{"MOM_SPIKE":0,"TAX_DROP":0,"HS_SHIFT":false,"VALUE_TAX_ANOMALY":false,"risk_weight":0}	55	HIGH	2026-02-15 18:25:58	2026-02-15 18:25:58	f	\N
82	7	19	{"RATE_MISMATCH":true,"BUYER_RISK":true,"BANKING_RISK":false,"STRUCTURE_ERROR":true}	{"MOM_SPIKE":0,"TAX_DROP":0,"HS_SHIFT":false,"VALUE_TAX_ANOMALY":false,"risk_weight":0}	55	HIGH	2026-02-15 18:26:21	2026-02-15 18:26:21	f	\N
83	7	19	{"RATE_MISMATCH":true,"BUYER_RISK":true,"BANKING_RISK":false,"STRUCTURE_ERROR":true}	{"MOM_SPIKE":0,"TAX_DROP":0,"HS_SHIFT":false,"VALUE_TAX_ANOMALY":false,"risk_weight":0}	55	HIGH	2026-02-15 18:26:25	2026-02-15 18:26:25	f	\N
84	7	19	{"RATE_MISMATCH":true,"BUYER_RISK":true,"BANKING_RISK":false,"STRUCTURE_ERROR":true}	{"MOM_SPIKE":0,"TAX_DROP":0,"HS_SHIFT":false,"VALUE_TAX_ANOMALY":false,"risk_weight":0}	55	HIGH	2026-02-15 18:27:24	2026-02-15 18:27:24	f	\N
85	7	19	{"RATE_MISMATCH":true,"BUYER_RISK":true,"BANKING_RISK":false,"STRUCTURE_ERROR":true}	{"MOM_SPIKE":0,"TAX_DROP":0,"HS_SHIFT":false,"VALUE_TAX_ANOMALY":false,"risk_weight":0}	55	HIGH	2026-02-15 18:27:27	2026-02-15 18:27:27	f	\N
86	7	26	{"RATE_MISMATCH":true,"BUYER_RISK":true,"BANKING_RISK":false,"STRUCTURE_ERROR":true}	{"MOM_SPIKE":0,"TAX_DROP":0,"HS_SHIFT":false,"VALUE_TAX_ANOMALY":false,"risk_weight":0}	55	HIGH	2026-02-16 06:01:53	2026-02-16 06:01:53	f	\N
87	7	26	{"RATE_MISMATCH":false,"BUYER_RISK":false,"BANKING_RISK":false,"STRUCTURE_ERROR":false}	{"MOM_SPIKE":0,"TAX_DROP":0,"HS_SHIFT":false,"VALUE_TAX_ANOMALY":false,"risk_weight":0}	100	LOW	2026-02-16 06:01:56	2026-02-16 06:45:58	t	{"RATE_MISMATCH":true,"BUYER_RISK":true,"BANKING_RISK":false,"STRUCTURE_ERROR":true}
88	7	27	{"RATE_MISMATCH":true,"BUYER_RISK":true,"BANKING_RISK":false,"STRUCTURE_ERROR":true}	{"MOM_SPIKE":0,"TAX_DROP":0,"HS_SHIFT":false,"VALUE_TAX_ANOMALY":false,"risk_weight":0}	55	HIGH	2026-02-16 12:54:54	2026-02-16 12:54:54	f	\N
89	7	27	{"RATE_MISMATCH":false,"BUYER_RISK":false,"BANKING_RISK":false,"STRUCTURE_ERROR":false}	{"MOM_SPIKE":0,"TAX_DROP":0,"HS_SHIFT":false,"VALUE_TAX_ANOMALY":false,"risk_weight":0}	100	LOW	2026-02-16 12:55:12	2026-02-16 12:55:14	t	{"RATE_MISMATCH":true,"BUYER_RISK":true,"BANKING_RISK":false,"STRUCTURE_ERROR":true}
90	7	28	{"RATE_MISMATCH":false,"BUYER_RISK":false,"BANKING_RISK":false,"STRUCTURE_ERROR":false}	{"MOM_SPIKE":0,"TAX_DROP":0,"HS_SHIFT":false,"VALUE_TAX_ANOMALY":false,"risk_weight":0}	100	LOW	2026-02-17 08:57:13	2026-02-17 08:57:15	t	{"RATE_MISMATCH":true,"BUYER_RISK":true,"BANKING_RISK":false,"STRUCTURE_ERROR":true}
92	7	30	{"RATE_MISMATCH":false,"BUYER_RISK":false,"BANKING_RISK":false,"STRUCTURE_ERROR":false}	{"MOM_SPIKE":0,"TAX_DROP":0,"HS_SHIFT":false,"VALUE_TAX_ANOMALY":false,"risk_weight":0}	100	LOW	2026-02-17 09:36:46	2026-02-17 09:36:48	t	{"RATE_MISMATCH":true,"BUYER_RISK":true,"BANKING_RISK":false,"STRUCTURE_ERROR":true}
91	7	\N	{"RATE_MISMATCH":false,"BUYER_RISK":true,"BANKING_RISK":false,"STRUCTURE_ERROR":true}	{"MOM_SPIKE":0,"TAX_DROP":0,"HS_SHIFT":false,"VALUE_TAX_ANOMALY":false,"risk_weight":0}	70	MODERATE	2026-02-17 09:27:21	2026-02-17 09:27:21	f	\N
\.


--
-- Data for Name: compliance_scores; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.compliance_scores (id, company_id, score, success_rate, retry_ratio, draft_aging, failure_ratio, category, calculated_date, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: customer_ledgers; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.customer_ledgers (id, company_id, customer_name, customer_ntn, invoice_id, debit, credit, balance_after, type, notes, created_at, updated_at) FROM stdin;
10	2	WALK IN CUSTOMER		8	70.80	0.00	70.80	invoice	Invoice DEMOT-000001 locked	2026-02-12 11:52:40	2026-02-12 11:52:40
11	7	walk in customer		9	273.22	0.00	273.22	invoice	Invoice ZIACO-000001 locked	2026-02-13 05:31:32	2026-02-13 05:31:32
12	7	NISAR		9	273.22	0.00	273.22	invoice	Invoice 3620291786117DI1770965899484 locked	2026-02-13 09:58:57	2026-02-13 09:58:57
13	7	rayan		14	52.50	0.00	52.50	invoice	Invoice 3620291786117DI1771076887931 locked	2026-02-14 13:55:56	2026-02-14 13:55:56
14	7	Abrar Ahmad		13	819.66	0.00	819.66	invoice	Invoice 3620291786117DI1771077549919 locked	2026-02-14 13:59:54	2026-02-14 13:59:54
16	7	NISAR		18	273.22	0.00	1092.88	invoice	Invoice 3620291786117DI1771165737745 locked	2026-02-15 14:45:05	2026-02-15 14:45:05
17	7	Sajjad		21	819.66	0.00	819.66	invoice	Invoice 3620291786117DI1771168876103 locked	2026-02-15 17:44:54	2026-02-15 17:44:54
18	7	walk in cutomer		26	2732.20	0.00	2732.20	invoice	Invoice 3620291786117DI1771221712284 locked	2026-02-16 06:01:58	2026-02-16 06:01:58
19	7	MALIK FAWAD		27	1366.10	0.00	1366.10	invoice	Invoice 3620291786117DI1771246493957 locked	2026-02-16 12:55:14	2026-02-16 12:55:14
20	7	walk in cutomer		28	1639.32	0.00	1639.32	invoice	Invoice 3620291786117DI1771318614634 locked	2026-02-17 08:57:15	2026-02-17 08:57:15
21	7	walk in cutomer		30	273.22	0.00	273.22	invoice	Invoice 3620291786117DI1771321000267 locked	2026-02-17 09:36:48	2026-02-17 09:36:48
\.


--
-- Data for Name: customer_profiles; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.customer_profiles (id, company_id, name, ntn, cnic, address, phone, email, registration_type, is_active, created_at, updated_at, province) FROM stdin;
1	2	JAAFIR	\N	\N	ghalla mandi kahror pakka	03087932990	6806641@gmail.com	Unregistered	t	2026-02-12 09:08:20	2026-02-12 09:08:20	\N
2	7	walk in cutomer	\N	\N	Kahror pakka	\N	\N	Unregistered	t	2026-02-14 06:09:24	2026-02-14 06:09:24	Punjab
\.


--
-- Data for Name: customer_tax_rules; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.customer_tax_rules (id, company_id, customer_ntn, hs_code, override_tax_rate, override_schedule_type, override_sro_required, override_mrp_required, description, is_active, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: failed_jobs; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.failed_jobs (id, uuid, connection, queue, payload, exception, failed_at) FROM stdin;
\.


--
-- Data for Name: fbr_logs; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.fbr_logs (id, invoice_id, request_payload, response_payload, status, created_at, updated_at, failure_type, response_time_ms, retry_count, environment_used, failure_category, submission_latency_ms, request_payload_hash) FROM stdin;
1	3	{"test":true}	{"status":"success"}	success	2026-02-11 10:22:41	2026-02-11 10:22:41	\N	1250	0	\N	\N	\N	\N
2	5	{"demo":true,"mock":true}	{"status":"success","fbr_invoice_number":"MOCK-FBR-0001","mock":true}	success	2026-02-11 11:46:17	2026-02-11 11:46:17	\N	850	0	\N	\N	\N	\N
3	8	{"invoiceType":"Sale Invoice","invoiceDate":"2026-02-12","sellerNTNCNIC":"9876543-2","sellerBusinessName":"Demo Traders Pvt Ltd","sellerProvince":"","sellerAddress":"I-8 Markaz, Islamabad","buyerNTNCNIC":null,"buyerBusinessName":"WALK IN CUSTOMER","buyerProvince":"Punjab","buyerAddress":"LODHRAN","buyerRegistrationType":"Unregistered","invoiceRefNo":"DEMOT-000001","items":[{"hsCode":"2202.1010","productDescription":"BEVERAGES","rate":"18%","uoM":"Liters","quantity":1,"totalValues":70.8,"valueSalesExcludingST":60,"fixedNotifiedValueOrRetailPrice":60,"salesTaxApplicable":10.8,"salesTaxWithheldAtSource":0,"extraTax":0,"furtherTax":0,"sroScheduleNo":"","fedPayable":0,"discount":0,"saleType":"Goods under 3rd Schedule","sroItemSerialNo":""}]}	{"errors":["Missing required field: sellerProvince","Missing required field: buyerNTNCNIC"]}	failed	2026-02-12 07:06:17	2026-02-12 07:06:17	payload_error	0	0	\N	\N	\N	\N
4	8	{"invoiceType":"Sale Invoice","invoiceDate":"2026-02-12","sellerNTNCNIC":"9876543-2","sellerBusinessName":"Demo Traders Pvt Ltd","sellerProvince":"","sellerAddress":"I-8 Markaz, Islamabad","buyerNTNCNIC":null,"buyerBusinessName":"WALK IN CUSTOMER","buyerProvince":"Punjab","buyerAddress":"LODHRAN","buyerRegistrationType":"Unregistered","invoiceRefNo":"DEMOT-000001","items":[{"hsCode":"2202.1010","productDescription":"BEVERAGES","rate":"18%","uoM":"Liters","quantity":1,"totalValues":70.8,"valueSalesExcludingST":60,"fixedNotifiedValueOrRetailPrice":60,"salesTaxApplicable":10.8,"salesTaxWithheldAtSource":0,"extraTax":0,"furtherTax":0,"sroScheduleNo":"","fedPayable":0,"discount":0,"saleType":"Goods under 3rd Schedule","sroItemSerialNo":""}]}	{"errors":["Missing required field: sellerProvince","Missing required field: buyerNTNCNIC"]}	failed	2026-02-12 07:06:47	2026-02-12 07:06:47	payload_error	0	1	\N	\N	\N	\N
5	8	{"invoiceType":"Sale Invoice","invoiceDate":"2026-02-12","sellerNTNCNIC":"9876543-2","sellerBusinessName":"Demo Traders Pvt Ltd","sellerProvince":"","sellerAddress":"I-8 Markaz, Islamabad","buyerNTNCNIC":null,"buyerBusinessName":"WALK IN CUSTOMER","buyerProvince":"Punjab","buyerAddress":"LODHRAN","buyerRegistrationType":"Unregistered","invoiceRefNo":"DEMOT-000001","items":[{"hsCode":"2202.1010","productDescription":"BEVERAGES","rate":"18%","uoM":"Liters","quantity":1,"totalValues":70.8,"valueSalesExcludingST":60,"fixedNotifiedValueOrRetailPrice":60,"salesTaxApplicable":10.8,"salesTaxWithheldAtSource":0,"extraTax":0,"furtherTax":0,"sroScheduleNo":"","fedPayable":0,"discount":0,"saleType":"Goods under 3rd Schedule","sroItemSerialNo":""}]}	{"errors":["Missing required field: sellerProvince","Missing required field: buyerNTNCNIC"]}	failed	2026-02-12 07:07:48	2026-02-12 07:07:48	payload_error	0	2	\N	\N	\N	\N
6	8	{"invoiceType":"Sale Invoice","invoiceDate":"2026-02-12","sellerNTNCNIC":"9876543-2","sellerBusinessName":"Demo Traders Pvt Ltd","sellerProvince":"Punjab","sellerAddress":"I-8 Markaz, Islamabad","buyerNTNCNIC":null,"buyerBusinessName":"WALK IN CUSTOMER","buyerProvince":"Punjab","buyerAddress":"LODHRAN","buyerRegistrationType":"Unregistered","invoiceRefNo":"DEMOT-000001","items":[{"hsCode":"2202.1010","productDescription":"BEVERAGES","rate":"18%","uoM":"Liters","quantity":1,"totalValues":70.8,"valueSalesExcludingST":60,"fixedNotifiedValueOrRetailPrice":60,"salesTaxApplicable":10.8,"salesTaxWithheldAtSource":0,"extraTax":0,"furtherTax":0,"sroScheduleNo":"","fedPayable":0,"discount":0,"saleType":"Goods under 3rd Schedule","sroItemSerialNo":""}],"demo_mode":true}	{"status":"success","fbr_invoice_number":"MOCK-FBR-1982","mock":true}	success	2026-02-12 08:51:11	2026-02-12 08:51:11	\N	1065	0	\N	\N	\N	\N
7	8	{"invoiceType":"Sale Invoice","invoiceDate":"2026-02-12","sellerNTNCNIC":"9876543-2","sellerBusinessName":"Demo Traders Pvt Ltd","sellerProvince":"Punjab","sellerAddress":"I-8 Markaz, Islamabad","buyerNTNCNIC":null,"buyerBusinessName":"WALK IN CUSTOMER","buyerProvince":"Punjab","buyerAddress":"LODHRAN","buyerRegistrationType":"Unregistered","invoiceRefNo":"DEMOT-000001","items":[{"hsCode":"2202.1010","productDescription":"BEVERAGES","rate":"18%","uoM":"Liters","quantity":1,"totalValues":70.8,"valueSalesExcludingST":60,"fixedNotifiedValueOrRetailPrice":60,"salesTaxApplicable":10.8,"salesTaxWithheldAtSource":0,"extraTax":0,"furtherTax":0,"sroScheduleNo":"","fedPayable":0,"discount":0,"saleType":"Goods under 3rd Schedule","sroItemSerialNo":""}],"demo_mode":true}	{"status":"success","fbr_invoice_number":"MOCK-FBR-4296","mock":true}	success	2026-02-12 08:51:41	2026-02-12 08:51:41	\N	563	0	\N	\N	\N	\N
132	19	{"items":[{"uoM":"KG","rate":"5%","hsCode":"3105.3000","discount":0,"extraTax":0,"quantity":4,"saleType":"3rd Schedule Goods","fedPayable":0,"furtherTax":0,"totalValues":1092.88,"productDescription":"Dap","salesTaxApplicable":52.04,"valueSalesExcludingST":1040.84,"salesTaxWithheldAtSource":0,"fixedNotifiedValueOrRetailPrice":260.21,"sroScheduleNo":"3rd Schedule goods","sroItemSerialNo":"51"}],"invoiceDate":"2026-02-15","invoiceType":"Sale Invoice","documentTypeId":1,"buyerAddress":"Lodhran","invoiceRefNo":"3620291786117DI1771169556175","buyerProvince":"Punjab","sellerAddress":"KHAIR PUR ROAD PULL FAREED ABAD KAHROR PAKKA, Lodhran Kahror Pakka","sellerNTNCNIC":"3620291786117","sellerProvince":"Punjab","buyerBusinessName":"Sajjad","sellerBusinessName":"ZIA CORPORATION","buyerRegistrationType":"Unregistered","buyerNTNCNIC":""}	{\n\n\t\t\t\t\t\t\t\t\t\t\t"dated": "2026-02-15 20:31:19" ,\n\t\t\t\t\t\t\t\t\t\t\t"validationResponse": {"statusCode":"01","status":"Invalid","error":"","invoiceStatuses":[{"itemSNo":"1","statusCode":"01","status":"Invalid","invoiceNo":null,"errorCode":"0102","error":"Provided sales tax amount does not match the calculated sales tax amount in case of 3rd schedule goods. Please ensure that the Fixed/Notified Value or Retail Price is used to calculated the Sales Tax Amount for the provided rate."}]}\n\n\t\t\t\t\t\t\t\t\t\t\t}	failed	2026-02-15 15:32:36	2026-02-15 15:32:37	validation_error	1461	0	\N	\N	\N	\N
8	8	{"invoiceType":"Sale Invoice","invoiceDate":"2026-02-12","sellerNTNCNIC":"9876543-2","sellerBusinessName":"Demo Traders Pvt Ltd","sellerProvince":"Punjab","sellerAddress":"I-8 Markaz, Islamabad","buyerNTNCNIC":null,"buyerBusinessName":"WALK IN CUSTOMER","buyerProvince":"Punjab","buyerAddress":"LODHRAN","buyerRegistrationType":"Unregistered","invoiceRefNo":"DEMOT-000001","items":[{"hsCode":"2202.1010","productDescription":"BEVERAGES","rate":"18%","uoM":"Liters","quantity":1,"totalValues":70.8,"valueSalesExcludingST":60,"fixedNotifiedValueOrRetailPrice":60,"salesTaxApplicable":10.8,"salesTaxWithheldAtSource":0,"extraTax":0,"furtherTax":0,"sroScheduleNo":"","fedPayable":0,"discount":0,"saleType":"Goods under 3rd Schedule","sroItemSerialNo":""}],"demo_mode":true}	{"status":"success","fbr_invoice_number":"MOCK-FBR-7667","mock":true}	success	2026-02-12 08:52:42	2026-02-12 08:52:42	\N	807	0	\N	\N	\N	\N
9	8	{"invoiceType":"Sale Invoice","invoiceDate":"2026-02-12","sellerNTNCNIC":"9876543-2","sellerBusinessName":"Demo Traders Pvt Ltd","sellerProvince":"Punjab","sellerAddress":"I-8 Markaz, Islamabad","buyerNTNCNIC":null,"buyerBusinessName":"WALK IN CUSTOMER","buyerProvince":"Punjab","buyerAddress":"LODHRAN","buyerRegistrationType":"Unregistered","invoiceRefNo":"DEMOT-000001","items":[{"hsCode":"2202.1010","productDescription":"BEVERAGES","rate":"18%","uoM":"Liters","quantity":1,"totalValues":70.8,"valueSalesExcludingST":60,"fixedNotifiedValueOrRetailPrice":60,"salesTaxApplicable":10.8,"salesTaxWithheldAtSource":0,"extraTax":0,"furtherTax":0,"sroScheduleNo":"","fedPayable":0,"discount":0,"saleType":"Goods under 3rd Schedule","sroItemSerialNo":""}],"demo_mode":true}	{"status":"success","fbr_invoice_number":"MOCK-FBR-6129","mock":true}	success	2026-02-12 09:41:50	2026-02-12 09:41:50	\N	1266	0	\N	\N	\N	\N
10	8	{"invoiceType":"Sale Invoice","invoiceDate":"2026-02-12","sellerNTNCNIC":"9876543-2","sellerBusinessName":"Demo Traders Pvt Ltd","sellerProvince":"Punjab","sellerAddress":"I-8 Markaz, Islamabad","buyerNTNCNIC":null,"buyerBusinessName":"WALK IN CUSTOMER","buyerProvince":"Punjab","buyerAddress":"LODHRAN","buyerRegistrationType":"Unregistered","invoiceRefNo":"DEMOT-000001","items":[{"hsCode":"2202.1010","productDescription":"BEVERAGES","rate":"18%","uoM":"Liters","quantity":1,"totalValues":70.8,"valueSalesExcludingST":60,"fixedNotifiedValueOrRetailPrice":60,"salesTaxApplicable":10.8,"salesTaxWithheldAtSource":0,"extraTax":0,"furtherTax":0,"sroScheduleNo":"","fedPayable":0,"discount":0,"saleType":"Goods under 3rd Schedule","sroItemSerialNo":""}],"demo_mode":true}	{"status":"success","fbr_invoice_number":"MOCK-FBR-2258","mock":true}	success	2026-02-12 09:42:20	2026-02-12 09:42:20	\N	623	0	\N	\N	\N	\N
11	8	{"invoiceType":"Sale Invoice","invoiceDate":"2026-02-12","sellerNTNCNIC":"9876543-2","sellerBusinessName":"Demo Traders Pvt Ltd","sellerProvince":"Punjab","sellerAddress":"I-8 Markaz, Islamabad","buyerNTNCNIC":null,"buyerBusinessName":"WALK IN CUSTOMER","buyerProvince":"Punjab","buyerAddress":"LODHRAN","buyerRegistrationType":"Unregistered","invoiceRefNo":"DEMOT-000001","items":[{"hsCode":"2202.1010","productDescription":"BEVERAGES","rate":"18%","uoM":"Liters","quantity":1,"totalValues":70.8,"valueSalesExcludingST":60,"fixedNotifiedValueOrRetailPrice":60,"salesTaxApplicable":10.8,"salesTaxWithheldAtSource":0,"extraTax":0,"furtherTax":0,"sroScheduleNo":"","fedPayable":0,"discount":0,"saleType":"Goods under 3rd Schedule","sroItemSerialNo":""}],"demo_mode":true}	{"status":"success","fbr_invoice_number":"MOCK-FBR-1091","mock":true}	success	2026-02-12 09:43:21	2026-02-12 09:43:21	\N	1298	0	\N	\N	\N	\N
12	8	{"invoiceType":"Sale Invoice","invoiceDate":"2026-02-12","sellerNTNCNIC":"9876543-2","sellerBusinessName":"Demo Traders Pvt Ltd","sellerProvince":"Punjab","sellerAddress":"I-8 Markaz, Islamabad","buyerNTNCNIC":null,"buyerBusinessName":"WALK IN CUSTOMER","buyerProvince":"Punjab","buyerAddress":"LODHRAN","buyerRegistrationType":"Unregistered","invoiceRefNo":"DEMOT-000001","items":[{"hsCode":"2202.1010","productDescription":"BEVERAGES","rate":"18%","uoM":"Liters","quantity":1,"totalValues":70.8,"valueSalesExcludingST":60,"fixedNotifiedValueOrRetailPrice":60,"salesTaxApplicable":10.8,"salesTaxWithheldAtSource":0,"extraTax":0,"furtherTax":0,"sroScheduleNo":"","fedPayable":0,"discount":0,"saleType":"Goods under 3rd Schedule","sroItemSerialNo":""}],"demo_mode":true}	{"status":"success","fbr_invoice_number":"MOCK-FBR-9205","mock":true}	success	2026-02-12 11:47:16	2026-02-12 11:47:16	\N	1202	0	\N	\N	\N	\N
13	8	{"invoiceType":"Sale Invoice","invoiceDate":"2026-02-12","sellerNTNCNIC":"9876543-2","sellerBusinessName":"Demo Traders Pvt Ltd","sellerProvince":"Punjab","sellerAddress":"I-8 Markaz, Islamabad","buyerNTNCNIC":null,"buyerBusinessName":"WALK IN CUSTOMER","buyerProvince":"Punjab","buyerAddress":"LODHRAN","buyerRegistrationType":"Unregistered","invoiceRefNo":"DEMOT-000001","items":[{"hsCode":"2202.1010","productDescription":"BEVERAGES","rate":"18%","uoM":"Liters","quantity":1,"totalValues":70.8,"valueSalesExcludingST":60,"fixedNotifiedValueOrRetailPrice":60,"salesTaxApplicable":10.8,"salesTaxWithheldAtSource":0,"extraTax":0,"furtherTax":0,"sroScheduleNo":"","fedPayable":0,"discount":0,"saleType":"Goods under 3rd Schedule","sroItemSerialNo":""}],"demo_mode":true}	{"status":"success","fbr_invoice_number":"MOCK-FBR-3752","mock":true}	success	2026-02-12 11:47:46	2026-02-12 11:47:46	\N	1322	0	\N	\N	\N	\N
14	8	{"invoiceType":"Sale Invoice","invoiceDate":"2026-02-12","sellerNTNCNIC":"9876543-2","sellerBusinessName":"Demo Traders Pvt Ltd","sellerProvince":"Punjab","sellerAddress":"I-8 Markaz, Islamabad","buyerNTNCNIC":null,"buyerBusinessName":"WALK IN CUSTOMER","buyerProvince":"Punjab","buyerAddress":"LODHRAN","buyerRegistrationType":"Unregistered","invoiceRefNo":"DEMOT-000001","items":[{"hsCode":"2202.1010","productDescription":"BEVERAGES","rate":"18%","uoM":"Liters","quantity":1,"totalValues":70.8,"valueSalesExcludingST":60,"fixedNotifiedValueOrRetailPrice":60,"salesTaxApplicable":10.8,"salesTaxWithheldAtSource":0,"extraTax":0,"furtherTax":0,"sroScheduleNo":"","fedPayable":0,"discount":0,"saleType":"Goods under 3rd Schedule","sroItemSerialNo":""}],"demo_mode":true}	{"status":"success","fbr_invoice_number":"MOCK-FBR-7214","mock":true}	success	2026-02-12 11:48:46	2026-02-12 11:48:46	\N	629	0	\N	\N	\N	\N
15	8	{"invoiceType":"Sale Invoice","invoiceDate":"2026-02-12","sellerNTNCNIC":"9876543-2","sellerBusinessName":"Demo Traders Pvt Ltd","sellerProvince":"Punjab","sellerAddress":"I-8 Markaz, Islamabad","buyerNTNCNIC":null,"buyerBusinessName":"WALK IN CUSTOMER","buyerProvince":"Punjab","buyerAddress":"LODHRAN","buyerRegistrationType":"Unregistered","invoiceRefNo":"DEMOT-000001","items":[{"hsCode":"2202.1010","productDescription":"BEVERAGES","rate":"18%","uoM":"Liters","quantity":1,"totalValues":70.8,"valueSalesExcludingST":60,"fixedNotifiedValueOrRetailPrice":60,"salesTaxApplicable":10.8,"salesTaxWithheldAtSource":0,"extraTax":0,"furtherTax":0,"sroScheduleNo":"","fedPayable":0,"discount":0,"saleType":"Goods under 3rd Schedule","sroItemSerialNo":""}],"demo_mode":true}	{"status":"success","fbr_invoice_number":"MOCK-FBR-4177","mock":true}	success	2026-02-12 11:52:40	2026-02-12 11:52:40	\N	801	0	\N	\N	\N	\N
16	9	{"invoiceType":"Sale Invoice","invoiceDate":"2026-02-13","sellerNTNCNIC":"3620291786117","sellerBusinessName":"ZIA CORPORATION","sellerProvince":"Punjab","sellerAddress":"KHAIR PUR ROAD PULL FAREED ABAD KAHROR PAKKA, Lodhran Kahror Pakka","buyerNTNCNIC":null,"buyerBusinessName":"walk in customer","buyerProvince":"Punjab","buyerAddress":"kahror pakka","buyerRegistrationType":"Unregistered","invoiceRefNo":"ZIACO-000001","items":[{"hsCode":"3105.3000","productDescription":"Dap","rate":"5%","uoM":"Kilograms","quantity":1,"totalValues":273.22,"valueSalesExcludingST":260.21,"fixedNotifiedValueOrRetailPrice":260.21,"salesTaxApplicable":13.01,"salesTaxWithheldAtSource":0,"extraTax":0,"furtherTax":0,"sroScheduleNo":"3rd Schedule goods","fedPayable":0,"discount":0,"saleType":"Goods under 3rd Schedule","sroItemSerialNo":"51"}],"demo_mode":true}	{"status":"success","fbr_invoice_number":"MOCK-FBR-2457","mock":true}	success	2026-02-13 05:31:32	2026-02-13 05:31:32	\N	1241	0	\N	\N	\N	\N
17	9	{"invoiceType":"Sale Invoice","invoiceDate":"2026-02-13","sellerNTNCNIC":"3620291786117","sellerBusinessName":"ZIA CORPORATION","sellerProvince":"Punjab","sellerAddress":"KHAIR PUR ROAD PULL FAREED ABAD KAHROR PAKKA, Lodhran Kahror Pakka","buyerNTNCNIC":null,"buyerBusinessName":"walk in customer","buyerProvince":"Punjab","buyerAddress":"kahror pakka","buyerRegistrationType":"Unregistered","invoiceRefNo":"ZIACO-000001","items":[{"hsCode":"3105.3000","productDescription":"Dap","rate":"5%","uoM":"Kilograms","quantity":1,"totalValues":273.22,"valueSalesExcludingST":260.21,"fixedNotifiedValueOrRetailPrice":260.21,"salesTaxApplicable":13.01,"salesTaxWithheldAtSource":0,"extraTax":0,"furtherTax":0,"sroScheduleNo":"3rd Schedule goods","fedPayable":0,"discount":0,"saleType":"Goods under 3rd Schedule","sroItemSerialNo":"51"}]}	{"fault":{"code":900901,"message":"Invalid Credentials","description":"Access failure for API: /di_data/v1, version: v1 status: (900901) - Invalid Credentials. Make sure you have given the correct access token"}}	failed	2026-02-13 05:39:30	2026-02-13 05:39:31	token_error	1096	0	\N	\N	\N	\N
18	9	{"invoiceType":"Sale Invoice","invoiceDate":"2026-02-13","sellerNTNCNIC":"3620291786117","sellerBusinessName":"ZIA CORPORATION","sellerProvince":"Punjab","sellerAddress":"KHAIR PUR ROAD PULL FAREED ABAD KAHROR PAKKA, Lodhran Kahror Pakka","buyerNTNCNIC":null,"buyerBusinessName":"walk in customer","buyerProvince":"Punjab","buyerAddress":"kahror pakka","buyerRegistrationType":"Unregistered","invoiceRefNo":"ZIACO-000001","items":[{"hsCode":"3105.3000","productDescription":"Dap","rate":"5%","uoM":"Kilograms","quantity":1,"totalValues":273.22,"valueSalesExcludingST":260.21,"fixedNotifiedValueOrRetailPrice":260.21,"salesTaxApplicable":13.01,"salesTaxWithheldAtSource":0,"extraTax":0,"furtherTax":0,"sroScheduleNo":"3rd Schedule goods","fedPayable":0,"discount":0,"saleType":"Goods under 3rd Schedule","sroItemSerialNo":"51"}]}	{"fault":{"code":900901,"message":"Invalid Credentials","description":"Access failure for API: /di_data/v1, version: v1 status: (900901) - Invalid Credentials. Make sure you have given the correct access token"}}	failed	2026-02-13 05:40:01	2026-02-13 05:40:02	token_error	1012	1	\N	\N	\N	\N
19	9	{"invoiceType":"Sale Invoice","invoiceDate":"2026-02-13","sellerNTNCNIC":"3620291786117","sellerBusinessName":"ZIA CORPORATION","sellerProvince":"Punjab","sellerAddress":"KHAIR PUR ROAD PULL FAREED ABAD KAHROR PAKKA, Lodhran Kahror Pakka","buyerNTNCNIC":null,"buyerBusinessName":"walk in customer","buyerProvince":"Punjab","buyerAddress":"kahror pakka","buyerRegistrationType":"Unregistered","invoiceRefNo":"ZIACO-000001","items":[{"hsCode":"3105.3000","productDescription":"Dap","rate":"5%","uoM":"Kilograms","quantity":1,"totalValues":273.22,"valueSalesExcludingST":260.21,"fixedNotifiedValueOrRetailPrice":260.21,"salesTaxApplicable":13.01,"salesTaxWithheldAtSource":0,"extraTax":0,"furtherTax":0,"sroScheduleNo":"3rd Schedule goods","fedPayable":0,"discount":0,"saleType":"Goods under 3rd Schedule","sroItemSerialNo":"51"}]}	{"fault":{"code":900901,"message":"Invalid Credentials","description":"Access failure for API: /di_data/v1, version: v1 status: (900901) - Invalid Credentials. Make sure you have given the correct access token"}}	failed	2026-02-13 05:41:02	2026-02-13 05:41:03	token_error	1002	2	\N	\N	\N	\N
20	9	{"invoiceType":"Sale Invoice","invoiceDate":"2026-02-13","sellerNTNCNIC":"3620291786117","sellerBusinessName":"ZIA CORPORATION","sellerProvince":"Punjab","sellerAddress":"KHAIR PUR ROAD PULL FAREED ABAD KAHROR PAKKA, Lodhran Kahror Pakka","buyerNTNCNIC":"","buyerBusinessName":"walk in customer","buyerProvince":"Punjab","buyerAddress":"kahror pakka","buyerRegistrationType":"Unregistered","invoiceRefNo":"","items":[{"hsCode":"3105.3000","productDescription":"Dap","rate":"5%","uoM":"Kilograms","quantity":1,"totalValues":273.22,"valueSalesExcludingST":260.21,"fixedNotifiedValueOrRetailPrice":260.21,"salesTaxApplicable":13.01,"salesTaxWithheldAtSource":0,"extraTax":0,"furtherTax":0,"sroScheduleNo":"3rd Schedule goods","fedPayable":0,"discount":0,"saleType":"Goods under 3rd Schedule","sroItemSerialNo":"51"}]}	\N	pending	2026-02-13 06:00:08	2026-02-13 06:00:08	\N	\N	0	\N	\N	\N	\N
35	9	{"invoiceType":"Sale Invoice","invoiceDate":"2026-02-13","sellerNTNCNIC":"3620291786117","sellerBusinessName":"ZIA UR REHMAN","sellerProvince":"Punjab","sellerAddress":"KHAIR PUR ROAD PULL FAREED ABAD KAHROR PAKKA, Lodhran Kahror Pakka","buyerNTNCNIC":null,"buyerBusinessName":"walk in customer","buyerProvince":"Punjab","buyerAddress":"kahror pakka","buyerRegistrationType":"Unregistered","invoiceRefNo":"3620291786117DI1770965899484","items":[{"hsCode":"3105.3000","productDescription":"Dap","rate":"5%","uoM":"Kilograms","quantity":1,"totalValues":273.22,"valueSalesExcludingST":260.21,"fixedNotifiedValueOrRetailPrice":260.21,"salesTaxApplicable":13.01,"salesTaxWithheldAtSource":0,"extraTax":0,"furtherTax":0,"sroScheduleNo":"3rd Schedule goods","fedPayable":0,"discount":0,"saleType":"Goods under 3rd Schedule","sroItemSerialNo":"51"}]}	{"fault":{"code":900901,"message":"Invalid Credentials","description":"Access failure for API: /di_data/v1, version: v1 status: (900901) - Invalid Credentials. Make sure you have given the correct access token"}}	failed	2026-02-13 07:35:19	2026-02-13 07:35:20	token_error	1104	0	\N	\N	\N	\N
133	19	{"items":[{"uoM":"KG","rate":"5%","hsCode":"3105.3000","discount":0,"extraTax":0,"quantity":4,"saleType":"3rd Schedule Goods","fedPayable":0,"furtherTax":0,"totalValues":1092.88,"productDescription":"Dap","salesTaxApplicable":52.04,"valueSalesExcludingST":1040.84,"salesTaxWithheldAtSource":0,"fixedNotifiedValueOrRetailPrice":1040.84,"sroScheduleNo":"3rd Schedule goods","sroItemSerialNo":"51"}],"invoiceDate":"2026-02-15","invoiceType":"Sale Invoice","documentTypeId":1,"buyerAddress":"Lodhran","invoiceRefNo":"3620291786117DI1771169651336","buyerProvince":"Punjab","sellerAddress":"KHAIR PUR ROAD PULL FAREED ABAD KAHROR PAKKA, Lodhran Kahror Pakka","sellerNTNCNIC":"3620291786117","sellerProvince":"Punjab","buyerBusinessName":"Sajjad","sellerBusinessName":"ZIA CORPORATION","buyerRegistrationType":"Unregistered","buyerNTNCNIC":""}	{\n\t\t\t\t\t\t\t\t\t\t\t"invoiceNumber": "3620291786117DIACPUFA742661",\n\t\t\t\t\t\t\t\t\t\t\t"dated": "2026-02-15 20:31:52",\n\t\t\t\t\t\t\t\t\t\t\t"validationResponse": {"statusCode":"00","status":"Valid","error":"","invoiceStatuses":[{"itemSNo":"1","statusCode":"00","status":"Valid","invoiceNo":"3620291786117DIACPUFA742661-1","errorCode":"","error":""}]}\n\n\t\t\t\t\t\t\t\t\t\t\t}	success	2026-02-15 15:34:11	2026-02-15 15:34:12	\N	1468	0	\N	\N	\N	\N
21	9	{"invoiceType":"Sale Invoice","invoiceDate":"2026-02-13","sellerNTNCNIC":"3620291786117","sellerBusinessName":"ZIA CORPORATION","sellerProvince":"Punjab","sellerAddress":"KHAIR PUR ROAD PULL FAREED ABAD KAHROR PAKKA, Lodhran Kahror Pakka","buyerNTNCNIC":"","buyerBusinessName":"walk in customer","buyerProvince":"Punjab","buyerAddress":"kahror pakka","buyerRegistrationType":"Unregistered","invoiceRefNo":"","items":[{"hsCode":"3105.3000","productDescription":"Dap","rate":"5%","uoM":"Kilograms","quantity":1,"totalValues":273.22,"valueSalesExcludingST":260.21,"fixedNotifiedValueOrRetailPrice":260.21,"salesTaxApplicable":13.01,"salesTaxWithheldAtSource":0,"extraTax":0,"furtherTax":0,"sroScheduleNo":"3rd Schedule goods","fedPayable":0,"discount":0,"saleType":"Goods under 3rd Schedule","sroItemSerialNo":"51"}]}		failed	2026-02-13 06:00:56	2026-02-13 06:00:57	invalid_response	1360	0	\N	\N	\N	\N
22	9	{"invoiceType":"Sale Invoice","invoiceDate":"2026-02-13","sellerNTNCNIC":"3620291786117","sellerBusinessName":"ZIA CORPORATION","sellerProvince":"Punjab","sellerAddress":"KHAIR PUR ROAD PULL FAREED ABAD KAHROR PAKKA, Lodhran Kahror Pakka","buyerNTNCNIC":"","buyerBusinessName":"walk in customer","buyerProvince":"Punjab","buyerAddress":"kahror pakka","buyerRegistrationType":"Unregistered","invoiceRefNo":"","items":[{"hsCode":"3105.3000","productDescription":"Dap","rate":"5%","uoM":"Kilograms","quantity":1,"totalValues":273.22,"valueSalesExcludingST":260.21,"fixedNotifiedValueOrRetailPrice":260.21,"salesTaxApplicable":13.01,"salesTaxWithheldAtSource":0,"extraTax":0,"furtherTax":0,"sroScheduleNo":"3rd Schedule goods","fedPayable":0,"discount":0,"saleType":"3rd Schedule Goods","sroItemSerialNo":"51"}]}		failed	2026-02-13 06:02:03	2026-02-13 06:02:05	invalid_response	1384	0	\N	\N	\N	\N
23	9	{"invoiceType":"Sale Invoice","invoiceDate":"2026-02-13","sellerNTNCNIC":"3620291786117","sellerBusinessName":"ZIA CORPORATION","sellerProvince":"Punjab","sellerAddress":"KHAIR PUR ROAD PULL FAREED ABAD KAHROR PAKKA, Lodhran Kahror Pakka","buyerNTNCNIC":"","buyerBusinessName":"walk in customer","buyerProvince":"Punjab","buyerAddress":"kahror pakka","buyerRegistrationType":"Unregistered","invoiceRefNo":"","items":[{"hsCode":"3105.3000","productDescription":"Dap","rate":"5%","uoM":"Kilograms","quantity":1,"totalValues":273.22,"valueSalesExcludingST":260.21,"fixedNotifiedValueOrRetailPrice":260.21,"salesTaxApplicable":13.01,"salesTaxWithheldAtSource":0,"extraTax":0,"furtherTax":0,"sroScheduleNo":"3rd Schedule goods","fedPayable":0,"discount":0,"saleType":"3rd Schedule Goods","sroItemSerialNo":"51"}]}	{\n                    "dated": "2026-02-13 11:04:40",\n                    "validationResponse": {\n                        "statusCode": "01",\n                        "status": "Invalid",\n                        "errorCode": "500",\n                        "error": "Some thing went wrong. Please try again later."\n                    } \n                }	failed	2026-02-13 06:06:57	2026-02-13 06:06:58	validation_error	1300	0	\N	\N	\N	\N
24	9	{"invoiceType":"Sale Invoice","invoiceDate":"2026-02-13","sellerNTNCNIC":"3620291786117","sellerBusinessName":"ZIA CORPORATION","sellerProvince":"PUNJAB","sellerAddress":"KHAIR PUR ROAD PULL FAREED ABAD KAHROR PAKKA, Lodhran Kahror Pakka","buyerNTNCNIC":"","buyerBusinessName":"walk in customer","buyerProvince":"PUNJAB","buyerAddress":"kahror pakka","buyerRegistrationType":"Unregistered","invoiceRefNo":"","items":[{"hsCode":"3105.3000","productDescription":"Dap","rate":"5%","uoM":"KG","quantity":1,"totalValues":273.22,"valueSalesExcludingST":260.21,"fixedNotifiedValueOrRetailPrice":260.21,"salesTaxApplicable":13.01,"salesTaxWithheldAtSource":0,"extraTax":0,"furtherTax":0,"sroScheduleNo":"3rd Schedule goods","fedPayable":0,"discount":0,"saleType":"3rd Schedule Goods","sroItemSerialNo":"51"}]}		failed	2026-02-13 06:14:14	2026-02-13 06:14:16	rate_limited	1358	0	\N	\N	\N	\N
25	9	{"invoiceType":"Sale Invoice","invoiceDate":"2026-02-13","sellerNTNCNIC":"3620291786117","sellerBusinessName":"ZIA CORPORATION","sellerProvince":"PUNJAB","sellerAddress":"KHAIR PUR ROAD PULL FAREED ABAD KAHROR PAKKA, Lodhran Kahror Pakka","buyerNTNCNIC":"","buyerBusinessName":"walk in customer","buyerProvince":"PUNJAB","buyerAddress":"kahror pakka","buyerRegistrationType":"Unregistered","invoiceRefNo":"","items":[{"hsCode":"3105.3000","productDescription":"Dap","rate":"5%","uoM":"KG","quantity":1,"totalValues":273.22,"valueSalesExcludingST":260.21,"fixedNotifiedValueOrRetailPrice":260.21,"salesTaxApplicable":13.01,"salesTaxWithheldAtSource":0,"extraTax":0,"furtherTax":0,"sroScheduleNo":"3rd Schedule goods","fedPayable":0,"discount":0,"saleType":"3rd Schedule Goods","sroItemSerialNo":"51"}]}		failed	2026-02-13 06:18:37	2026-02-13 06:18:39	rate_limited	1539	0	\N	\N	\N	\N
26	9	{"invoiceType":"Sale Invoice","invoiceDate":"2026-02-13","sellerNTNCNIC":"3620291786117","sellerBusinessName":"ZIA CORPORATION","sellerProvince":"PUNJAB","sellerAddress":"KHAIR PUR ROAD PULL FAREED ABAD KAHROR PAKKA, Lodhran Kahror Pakka","buyerNTNCNIC":"","buyerBusinessName":"walk in customer","buyerProvince":"PUNJAB","buyerAddress":"kahror pakka","buyerRegistrationType":"Unregistered","invoiceRefNo":"","items":[{"hsCode":"3105.3000","productDescription":"Dap","rate":"5%","uoM":"KG","quantity":1,"totalValues":273.22,"valueSalesExcludingST":260.21,"fixedNotifiedValueOrRetailPrice":260.21,"salesTaxApplicable":13.01,"salesTaxWithheldAtSource":0,"extraTax":0,"furtherTax":0,"sroScheduleNo":"3rd Schedule goods","fedPayable":0,"discount":0,"saleType":"3rd Schedule Goods","sroItemSerialNo":"51"}]}		failed	2026-02-13 06:50:36	2026-02-13 06:50:37	rate_limited	1535	0	\N	\N	\N	\N
27	9	{"invoiceType":"Sale Invoice","invoiceDate":"2026-02-13","sellerNTNCNIC":"3620291786117","sellerBusinessName":"ZIA CORPORATION","sellerProvince":"PUNJAB","sellerAddress":"KHAIR PUR ROAD PULL FAREED ABAD KAHROR PAKKA, Lodhran Kahror Pakka","buyerNTNCNIC":"","buyerBusinessName":"walk in customer","buyerProvince":"PUNJAB","buyerAddress":"kahror pakka","buyerRegistrationType":"Unregistered","invoiceRefNo":"","items":[{"hsCode":"3105.3000","productDescription":"Dap","rate":"5%","uoM":"KG","quantity":1,"totalValues":273.22,"valueSalesExcludingST":260.21,"fixedNotifiedValueOrRetailPrice":260.21,"salesTaxApplicable":13.01,"salesTaxWithheldAtSource":0,"extraTax":0,"furtherTax":0,"sroScheduleNo":"3rd Schedule goods","fedPayable":0,"discount":0,"saleType":"3rd Schedule Goods","sroItemSerialNo":"51"}]}		failed	2026-02-13 06:56:36	2026-02-13 06:56:37	rate_limited	1404	0	\N	\N	\N	\N
28	9	{"invoiceType":"Sale Invoice","invoiceDate":"2026-02-13","sellerNTNCNIC":"3620291786117","sellerBusinessName":"ZIA CORPORATION","sellerProvince":"PUNJAB","sellerAddress":"KHAIR PUR ROAD PULL FAREED ABAD KAHROR PAKKA, Lodhran Kahror Pakka","buyerNTNCNIC":"","buyerBusinessName":"walk in customer","buyerProvince":"PUNJAB","buyerAddress":"kahror pakka","buyerRegistrationType":"Unregistered","invoiceRefNo":"","items":[{"hsCode":"3105.3000","productDescription":"Dap","rate":"5%","uoM":"KG","quantity":1,"totalValues":273.22,"valueSalesExcludingST":260.21,"fixedNotifiedValueOrRetailPrice":260.21,"salesTaxApplicable":13.01,"salesTaxWithheldAtSource":0,"extraTax":0,"furtherTax":0,"sroScheduleNo":"3rd Schedule goods","fedPayable":0,"discount":0,"saleType":"3rd Schedule Goods","sroItemSerialNo":"51"}]}		failed	2026-02-13 06:58:30	2026-02-13 06:58:32	rate_limited	1251	0	\N	\N	\N	\N
29	9	{"invoiceType":"Sale Invoice","invoiceDate":"2026-02-13","sellerNTNCNIC":"3620291786117","sellerBusinessName":"ZIA UR REHMAN","sellerProvince":"PUNJAB","sellerAddress":"KHAIR PUR ROAD PULL FAREED ABAD KAHROR PAKKA, Lodhran Kahror Pakka","buyerNTNCNIC":"","buyerBusinessName":"walk in customer","buyerProvince":"PUNJAB","buyerAddress":"kahror pakka","buyerRegistrationType":"Unregistered","invoiceRefNo":"","items":[{"hsCode":"3105.3000","productDescription":"Dap","rate":"5%","uoM":"KG","quantity":1,"totalValues":273.22,"valueSalesExcludingST":260.21,"fixedNotifiedValueOrRetailPrice":260.21,"salesTaxApplicable":13.01,"salesTaxWithheldAtSource":0,"extraTax":0,"furtherTax":0,"sroScheduleNo":"3rd Schedule goods","fedPayable":0,"discount":0,"saleType":"3rd Schedule Goods","sroItemSerialNo":"51"}]}		failed	2026-02-13 07:09:34	2026-02-13 07:09:36	rate_limited	1453	0	\N	\N	\N	\N
30	9	{"invoiceType":"Sale Invoice","invoiceDate":"2026-02-13","sellerNTNCNIC":"3620291786117","sellerBusinessName":"ZIA UR REHMAN","sellerProvince":"PUNJAB","sellerAddress":"KHAIR PUR ROAD PULL FAREED ABAD KAHROR PAKKA, Lodhran Kahror Pakka","buyerNTNCNIC":"","buyerBusinessName":"walk in customer","buyerProvince":"PUNJAB","buyerAddress":"kahror pakka","buyerRegistrationType":"Unregistered","invoiceRefNo":"","items":[{"hsCode":"3105.3000","productDescription":"Dap","rate":"5%","uoM":"KG","quantity":1,"totalValues":273.22,"valueSalesExcludingST":260.21,"fixedNotifiedValueOrRetailPrice":260.21,"salesTaxApplicable":13.01,"salesTaxWithheldAtSource":0,"extraTax":0,"furtherTax":0,"sroScheduleNo":"3rd Schedule goods","fedPayable":0,"discount":0,"saleType":"3rd Schedule Goods","sroItemSerialNo":"51"}]}		failed	2026-02-13 07:18:15	2026-02-13 07:18:16	rate_limited	1235	1	\N	\N	\N	\N
31	9	{"invoiceType":"Sale Invoice","invoiceDate":"2026-02-13","sellerNTNCNIC":"3620291786117","sellerBusinessName":"ZIA UR REHMAN","sellerProvince":"PUNJAB","sellerAddress":"KHAIR PUR ROAD PULL FAREED ABAD KAHROR PAKKA, Lodhran Kahror Pakka","buyerNTNCNIC":"","buyerBusinessName":"walk in customer","buyerProvince":"PUNJAB","buyerAddress":"kahror pakka","buyerRegistrationType":"Unregistered","invoiceRefNo":"","items":[{"hsCode":"3105.3000","productDescription":"Dap","rate":"5%","uoM":"KG","quantity":1,"totalValues":273.22,"valueSalesExcludingST":260.21,"fixedNotifiedValueOrRetailPrice":260.21,"salesTaxApplicable":13.01,"salesTaxWithheldAtSource":0,"extraTax":0,"furtherTax":0,"sroScheduleNo":"3rd Schedule goods","fedPayable":0,"discount":0,"saleType":"3rd Schedule Goods","sroItemSerialNo":"51"}]}		failed	2026-02-13 07:19:24	2026-02-13 07:19:25	rate_limited	1145	1	\N	\N	\N	\N
32	9	{"invoiceType":"Sale Invoice","invoiceDate":"2026-02-13","sellerNTNCNIC":"3620291786117","sellerBusinessName":"ZIA UR REHMAN","sellerProvince":"PUNJAB","sellerAddress":"KHAIR PUR ROAD PULL FAREED ABAD KAHROR PAKKA, Lodhran Kahror Pakka","buyerNTNCNIC":"","buyerBusinessName":"walk in customer","buyerProvince":"PUNJAB","buyerAddress":"kahror pakka","buyerRegistrationType":"Unregistered","invoiceRefNo":"","items":[{"hsCode":"3105.3000","productDescription":"Dap","rate":"5%","uoM":"KG","quantity":1,"totalValues":273.22,"valueSalesExcludingST":260.21,"fixedNotifiedValueOrRetailPrice":260.21,"salesTaxApplicable":13.01,"salesTaxWithheldAtSource":0,"extraTax":0,"furtherTax":0,"sroScheduleNo":"3rd Schedule goods","fedPayable":0,"discount":0,"saleType":"3rd Schedule Goods","sroItemSerialNo":"51"}]}		failed	2026-02-13 07:20:26	2026-02-13 07:20:27	rate_limited	1079	1	\N	\N	\N	\N
33	9	{"invoiceType":"Sale Invoice","invoiceDate":"2026-02-13","sellerNTNCNIC":"3620291786117","sellerBusinessName":"ZIA UR REHMAN","sellerProvince":"PUNJAB","sellerAddress":"KHAIR PUR ROAD PULL FAREED ABAD KAHROR PAKKA, Lodhran Kahror Pakka","buyerNTNCNIC":"","buyerBusinessName":"walk in customer","buyerProvince":"PUNJAB","buyerAddress":"kahror pakka","buyerRegistrationType":"Unregistered","invoiceRefNo":"","items":[{"hsCode":"3105.3000","productDescription":"Dap","rate":"5%","uoM":"KG","quantity":1,"totalValues":273.22,"valueSalesExcludingST":260.21,"fixedNotifiedValueOrRetailPrice":260.21,"salesTaxApplicable":13.01,"salesTaxWithheldAtSource":0,"extraTax":0,"furtherTax":0,"sroScheduleNo":"3rd Schedule goods","fedPayable":0,"discount":0,"saleType":"3rd Schedule Goods","sroItemSerialNo":"51"}]}		failed	2026-02-13 07:33:29	2026-02-13 07:33:30	rate_limited	1404	0	\N	\N	\N	\N
34	9	{"items":[{"hsCode":"3105.3000","productDescription":"Dap","rate":"5%","uoM":"KG","quantity":1,"totalValues":273.22,"valueSalesExcludingST":260.21,"fixedNotifiedValueOrRetailPrice":260.21,"salesTaxApplicable":13.01,"salesTaxWithheldAtSource":0,"extraTax":0,"furtherTax":0,"fedPayable":0,"discount":0,"saleType":"3rd Schedule Goods","sroScheduleNo":"3rd Schedule goods","sroItemSerialNo":"51"}],"invoiceType":"Sale Invoice","invoiceDate":"2026-02-13","documentTypeId":1,"sellerNTNCNIC":"3620291786117","sellerBusinessName":"ZIA UR REHMAN","sellerProvince":"PUNJAB","sellerAddress":"KHAIR PUR ROAD PULL FAREED ABAD KAHROR PAKKA, Lodhran Kahror Pakka","buyerNTNCNIC":"","buyerBusinessName":"walk in customer","buyerProvince":"PUNJAB","buyerAddress":"kahror pakka","buyerRegistrationType":"Unregistered","invoiceRefNo":""}	{\n                    "dated": "2026-02-13 12:33:20",\n                    "validationResponse": {\n                        "statusCode": "01",\n                        "status": "Invalid",\n                        "errorCode": "500",\n                        "error": "Some thing went wrong. Please try again later."\n                    } \n                }	failed	2026-02-13 07:34:58	2026-02-13 07:34:59	validation_error	1397	1	\N	\N	\N	\N
36	9	{"invoiceType":"Sale Invoice","invoiceDate":"2026-02-13","sellerNTNCNIC":"3620291786117","sellerBusinessName":"ZIA UR REHMAN","sellerProvince":"Punjab","sellerAddress":"KHAIR PUR ROAD PULL FAREED ABAD KAHROR PAKKA, Lodhran Kahror Pakka","buyerNTNCNIC":null,"buyerBusinessName":"walk in customer","buyerProvince":"Punjab","buyerAddress":"kahror pakka","buyerRegistrationType":"Unregistered","invoiceRefNo":"3620291786117DI1770965899484","items":[{"hsCode":"3105.3000","productDescription":"Dap","rate":"5%","uoM":"Kilograms","quantity":1,"totalValues":273.22,"valueSalesExcludingST":260.21,"fixedNotifiedValueOrRetailPrice":260.21,"salesTaxApplicable":13.01,"salesTaxWithheldAtSource":0,"extraTax":0,"furtherTax":0,"sroScheduleNo":"3rd Schedule goods","fedPayable":0,"discount":0,"saleType":"Goods under 3rd Schedule","sroItemSerialNo":"51"}]}	{"fault":{"code":900901,"message":"Invalid Credentials","description":"Access failure for API: /di_data/v1, version: v1 status: (900901) - Invalid Credentials. Make sure you have given the correct access token"}}	failed	2026-02-13 07:35:50	2026-02-13 07:35:51	token_error	1063	1	\N	\N	\N	\N
134	22	{"items":[{"uoM":"KG","rate":"5%","hsCode":"3105.3000","discount":0,"extraTax":0,"quantity":50,"saleType":"3rd Schedule Goods","fedPayable":0,"furtherTax":0,"totalValues":13661.03,"productDescription":"Fertilizer - DAP (3105.3000)","salesTaxApplicable":650.53,"valueSalesExcludingST":13010.5,"salesTaxWithheldAtSource":0,"fixedNotifiedValueOrRetailPrice":13010.5,"sroScheduleNo":"3rd Schedule goods","sroItemSerialNo":"51"}],"invoiceDate":"2026-02-15","invoiceType":"Sale Invoice","documentTypeId":1,"buyerAddress":"Lahore, Pakistan","invoiceRefNo":"3620291786117DI1771170733600","buyerProvince":"Punjab","sellerAddress":"KHAIR PUR ROAD PULL FAREED ABAD KAHROR PAKKA, Lodhran Kahror Pakka","sellerNTNCNIC":"3620291786117","sellerProvince":"Punjab","buyerBusinessName":"Walk-in Customer","sellerBusinessName":"ZIA CORPORATION","buyerRegistrationType":"Unregistered","buyerNTNCNIC":""}	{\n\t\t\t\t\t\t\t\t\t\t\t"invoiceNumber": "3620291786117DIACPUAT065851",\n\t\t\t\t\t\t\t\t\t\t\t"dated": "2026-02-15 20:52:45",\n\t\t\t\t\t\t\t\t\t\t\t"validationResponse": {"statusCode":"00","status":"Valid","error":"","invoiceStatuses":[{"itemSNo":"1","statusCode":"00","status":"Valid","invoiceNo":"3620291786117DIACPUAT065851-1","errorCode":"","error":""}]}\n\n\t\t\t\t\t\t\t\t\t\t\t}	success	2026-02-15 15:52:46	2026-02-15 15:52:47	\N	1548	0	\N	\N	\N	bd3d9a4abec838b205cd88ef5d2655c099e22b70ac9c787ff50f21429a878a2e
37	9	{"invoiceType":"Sale Invoice","invoiceDate":"2026-02-13","sellerNTNCNIC":"3620291786117","sellerBusinessName":"ZIA UR REHMAN","sellerProvince":"Punjab","sellerAddress":"KHAIR PUR ROAD PULL FAREED ABAD KAHROR PAKKA, Lodhran Kahror Pakka","buyerNTNCNIC":null,"buyerBusinessName":"walk in customer","buyerProvince":"Punjab","buyerAddress":"kahror pakka","buyerRegistrationType":"Unregistered","invoiceRefNo":"3620291786117DI1770965899484","items":[{"hsCode":"3105.3000","productDescription":"Dap","rate":"5%","uoM":"Kilograms","quantity":1,"totalValues":273.22,"valueSalesExcludingST":260.21,"fixedNotifiedValueOrRetailPrice":260.21,"salesTaxApplicable":13.01,"salesTaxWithheldAtSource":0,"extraTax":0,"furtherTax":0,"sroScheduleNo":"3rd Schedule goods","fedPayable":0,"discount":0,"saleType":"Goods under 3rd Schedule","sroItemSerialNo":"51"}]}	{"fault":{"code":900901,"message":"Invalid Credentials","description":"Access failure for API: /di_data/v1, version: v1 status: (900901) - Invalid Credentials. Make sure you have given the correct access token"}}	failed	2026-02-13 07:36:51	2026-02-13 07:36:52	token_error	1006	2	\N	\N	\N	\N
38	9	{"items":[{"hsCode":"3105.3000","productDescription":"Dap","rate":"5%","uoM":"KG","quantity":1,"totalValues":273.22,"valueSalesExcludingST":260.21,"fixedNotifiedValueOrRetailPrice":260.21,"salesTaxApplicable":13.01,"salesTaxWithheldAtSource":0,"extraTax":0,"furtherTax":0,"fedPayable":0,"discount":0,"saleType":"3rd Schedule Goods","sroScheduleNo":"3rd Schedule goods","sroItemSerialNo":"51"}],"invoiceType":"Sale Invoice","invoiceDate":"2026-02-13","sellerNTNCNIC":"3620291786117","sellerBusinessName":"ZIA UR REHMAN","sellerProvince":"PUNJAB","sellerAddress":"KHAIR PUR ROAD PULL FAREED ABAD KAHROR PAKKA, Lodhran Kahror Pakka","buyerNTNCNIC":"","buyerBusinessName":"walk in customer","buyerProvince":"PUNJAB","buyerAddress":"kahror pakka","buyerRegistrationType":"Unregistered","invoiceRefNo":""}		failed	2026-02-13 07:37:06	2026-02-13 07:37:07	rate_limited	1191	0	\N	\N	\N	\N
39	9	{"items":[{"hsCode":"3105.3000","productDescription":"Dap","rate":"5%","uoM":"KG","quantity":1,"totalValues":273.22,"valueSalesExcludingST":260.21,"fixedNotifiedValueOrRetailPrice":260.21,"salesTaxApplicable":13.01,"salesTaxWithheldAtSource":0,"extraTax":0,"furtherTax":0,"fedPayable":0,"discount":0,"saleType":"3rd Schedule Goods","sroScheduleNo":"3rd Schedule goods","sroItemSerialNo":"51"}],"invoiceType":"Sale Invoice","invoiceDate":"2026-02-13","sellerNTNCNIC":"3620291786117","sellerBusinessName":"ZIA UR REHMAN","sellerProvince":"PUNJAB","sellerAddress":"KHAIR PUR ROAD PULL FAREED ABAD KAHROR PAKKA, Lodhran Kahror Pakka","buyerNTNCNIC":"","buyerBusinessName":"walk in customer","buyerProvince":"PUNJAB","buyerAddress":"kahror pakka","buyerRegistrationType":"Unregistered","invoiceRefNo":""}	{\n                    "dated": "2026-02-13 12:36:11",\n                    "validationResponse": {\n                        "statusCode": "01",\n                        "status": "Invalid",\n                        "errorCode": "500",\n                        "error": "Some thing went wrong. Please try again later."\n                    } \n                }	failed	2026-02-13 07:37:25	2026-02-13 07:37:26	validation_error	1041	1	\N	\N	\N	\N
40	9	{"items":[{"hsCode":"3105.3000","productDescription":"Dap","rate":"5%","uoM":"KG","quantity":1,"totalValues":273.22,"valueSalesExcludingST":260.21,"fixedNotifiedValueOrRetailPrice":260.21,"salesTaxApplicable":13.01,"salesTaxWithheldAtSource":0,"extraTax":0,"furtherTax":0,"fedPayable":0,"discount":0,"saleType":"3rd Schedule Goods","sroScheduleNo":"3rd Schedule goods","sroItemSerialNo":"51"}],"invoiceType":"Sale Invoice","invoiceDate":"2026-02-13","sellerNTNCNIC":"3620291786117","sellerBusinessName":"ZIA UR REHMAN","sellerProvince":"PUNJAB","sellerAddress":"KHAIR PUR ROAD PULL FAREED ABAD KAHROR PAKKA, Lodhran Kahror Pakka","buyerNTNCNIC":"","buyerBusinessName":"walk in customer","buyerProvince":"PUNJAB","buyerAddress":"kahror pakka","buyerRegistrationType":"Unregistered","invoiceRefNo":""}	{\n                    "dated": "2026-02-13 12:36:26",\n                    "validationResponse": {\n                        "statusCode": "01",\n                        "status": "Invalid",\n                        "errorCode": "500",\n                        "error": "Some thing went wrong. Please try again later."\n                    } \n                }	failed	2026-02-13 07:38:42	2026-02-13 07:38:44	validation_error	1547	0	\N	\N	\N	\N
41	9	{"items":[{"uoM":"KG","rate":"5%","hsCode":"3105.3000","discount":0,"extraTax":0,"quantity":1,"saleType":"3rd Schedule Goods","fedPayable":0,"furtherTax":0,"totalValues":273.22,"productDescription":"Dap","salesTaxApplicable":13.01,"valueSalesExcludingST":260.21,"salesTaxWithheldAtSource":0,"fixedNotifiedValueOrRetailPrice":260.21,"sroScheduleNo":"3rd Schedule goods","sroItemSerialNo":"51"}],"invoiceDate":"2026-02-13","invoiceType":"Sale Invoice","documentTypeId":1,"buyerAddress":"kahror pakka","invoiceRefNo":"3620291786117DI1770965899484","buyerProvince":"Punjab","sellerAddress":"KHAIR PUR ROAD PULL FAREED ABAD KAHROR PAKKA, Lodhran Kahror Pakka","sellerNTNCNIC":"3620291786117","sellerProvince":"Punjab","buyerBusinessName":"walk in customer","sellerBusinessName":"ZIA UR REHMAN","buyerRegistrationType":"Unregistered","buyerNTNCNIC":""}		failed	2026-02-13 07:43:39	2026-02-13 07:43:41	rate_limited	1168	1	\N	\N	\N	\N
42	9	{"items":[{"uoM":"KG","rate":"5%","hsCode":"3105.3000","discount":0,"extraTax":0,"quantity":1,"saleType":"3rd Schedule Goods","fedPayable":0,"furtherTax":0,"totalValues":273.22,"productDescription":"Dap","salesTaxApplicable":13.01,"valueSalesExcludingST":260.21,"salesTaxWithheldAtSource":0,"fixedNotifiedValueOrRetailPrice":260.21,"sroScheduleNo":"3rd Schedule goods","sroItemSerialNo":"51"}],"invoiceDate":"2026-02-13","invoiceType":"Sale Invoice","documentTypeId":1,"buyerAddress":"kahror pakka","invoiceRefNo":"3620291786117DI1770965899484","buyerProvince":"Punjab","sellerAddress":"KHAIR PUR ROAD PULL FAREED ABAD KAHROR PAKKA, Lodhran Kahror Pakka","sellerNTNCNIC":"3620291786117","sellerProvince":"Punjab","buyerBusinessName":"walk in customer","sellerBusinessName":"ZIA UR REHMAN","buyerRegistrationType":"Unregistered","buyerNTNCNIC":""}	{\n                    "dated": "2026-02-13 12:48:56",\n                    "validationResponse": {\n                        "statusCode": "01",\n                        "status": "Invalid",\n                        "errorCode": "500",\n                        "error": "Some thing went wrong. Please try again later."\n                    } \n                }	failed	2026-02-13 07:50:33	2026-02-13 07:50:35	validation_error	1454	1	\N	\N	\N	\N
135	23	{"items":[{"uoM":"KG","rate":"5%","hsCode":"3105.3000","discount":0,"extraTax":0,"quantity":100,"saleType":"3rd Schedule Goods","fedPayable":0,"furtherTax":0,"totalValues":27322.05,"productDescription":"Fertilizer - DAP (3105.3000)","salesTaxApplicable":1301.05,"valueSalesExcludingST":26021,"salesTaxWithheldAtSource":0,"fixedNotifiedValueOrRetailPrice":26021,"sroScheduleNo":"3rd Schedule goods","sroItemSerialNo":"51"}],"invoiceDate":"2026-02-15","invoiceType":"Sale Invoice","documentTypeId":1,"buyerAddress":"Lahore, Pakistan","invoiceRefNo":"3620291786117DI1771170733992","buyerProvince":"Punjab","sellerAddress":"KHAIR PUR ROAD PULL FAREED ABAD KAHROR PAKKA, Lodhran Kahror Pakka","sellerNTNCNIC":"3620291786117","sellerProvince":"Punjab","buyerBusinessName":"Walk-in Customer","sellerBusinessName":"ZIA CORPORATION","buyerRegistrationType":"Unregistered","buyerNTNCNIC":""}	{\n\t\t\t\t\t\t\t\t\t\t\t"invoiceNumber": "3620291786117DIACPUZZ535641",\n\t\t\t\t\t\t\t\t\t\t\t"dated": "2026-02-15 20:51:51",\n\t\t\t\t\t\t\t\t\t\t\t"validationResponse": {"statusCode":"00","status":"Valid","error":"","invoiceStatuses":[{"itemSNo":"1","statusCode":"00","status":"Valid","invoiceNo":"3620291786117DIACPUZZ535641-1","errorCode":"","error":""}]}\n\n\t\t\t\t\t\t\t\t\t\t\t}	success	2026-02-15 15:53:07	2026-02-15 15:53:09	\N	1595	0	\N	\N	\N	d48b2f8999a8c60388dc1bc53f4bf7ba74f2540f50a90b0b0c6eac6d62d7666c
43	9	{"items":[{"uoM":"KG","rate":"5%","hsCode":"3105.3000","discount":0,"extraTax":0,"quantity":1,"saleType":"3rd Schedule Goods","fedPayable":0,"furtherTax":0,"totalValues":273.22,"productDescription":"Dap","salesTaxApplicable":13.01,"valueSalesExcludingST":260.21,"salesTaxWithheldAtSource":0,"fixedNotifiedValueOrRetailPrice":260.21,"sroScheduleNo":"3rd Schedule goods","sroItemSerialNo":"51"}],"invoiceDate":"2026-02-13","invoiceType":"Sale Invoice","documentTypeId":1,"buyerAddress":"kahror pakka","invoiceRefNo":"3620291786117DI1770965899484","buyerProvince":"Punjab","sellerAddress":"KHAIR PUR ROAD PULL FAREED ABAD KAHROR PAKKA, Lodhran Kahror Pakka","sellerNTNCNIC":"3620291786117","sellerProvince":"Punjab","buyerBusinessName":"walk in customer","sellerBusinessName":"ZIA UR REHMAN","buyerRegistrationType":"Unregistered","buyerNTNCNIC":""}		failed	2026-02-13 07:53:38	2026-02-13 07:53:39	rate_limited	1177	1	\N	\N	\N	\N
44	9	{"items":[{"uoM":"KG","rate":"5%","hsCode":"3105.3000","discount":0,"extraTax":0,"quantity":1,"saleType":"3rd Schedule Goods","fedPayable":0,"furtherTax":0,"totalValues":273.22,"productDescription":"Dap","salesTaxApplicable":13.01,"valueSalesExcludingST":260.21,"salesTaxWithheldAtSource":0,"fixedNotifiedValueOrRetailPrice":260.21,"sroScheduleNo":"3rd Schedule goods","sroItemSerialNo":"51"}],"invoiceDate":"2026-02-13","invoiceType":"Sale Invoice","documentTypeId":1,"buyerAddress":"kahror pakka","invoiceRefNo":"3620291786117DI1770965899484","buyerProvince":"Punjab","sellerAddress":"KHAIR PUR ROAD PULL FAREED ABAD KAHROR PAKKA, Lodhran Kahror Pakka","sellerNTNCNIC":"3620291786117","sellerProvince":"Punjab","buyerBusinessName":"walk in customer","sellerBusinessName":"ZIA CORPORATION","buyerRegistrationType":"Unregistered","buyerNTNCNIC":""}		failed	2026-02-13 08:01:52	2026-02-13 08:01:54	rate_limited	1244	1	\N	\N	\N	\N
45	9	{"items":[{"uoM":"KG","rate":"5%","hsCode":"3105.3000","discount":0,"extraTax":0,"quantity":1,"saleType":"3rd Schedule Goods","fedPayable":0,"furtherTax":0,"totalValues":273.22,"productDescription":"Dap","salesTaxApplicable":13.01,"valueSalesExcludingST":260.21,"salesTaxWithheldAtSource":0,"fixedNotifiedValueOrRetailPrice":260.21,"sroScheduleNo":"3rd Schedule goods","sroItemSerialNo":"51"}],"invoiceDate":"2026-02-13","invoiceType":"Sale Invoice","documentTypeId":1,"buyerAddress":"kahror pakka","invoiceRefNo":"3620291786117DI1770965899484","buyerProvince":"Punjab","sellerAddress":"KHAIR PUR ROAD PULL FAREED ABAD KAHROR PAKKA, Lodhran Kahror Pakka","sellerNTNCNIC":"3620291786117","sellerProvince":"Punjab","buyerBusinessName":"walk in customer","sellerBusinessName":"ZIA CORPORATION","buyerRegistrationType":"Unregistered","buyerNTNCNIC":""}		failed	2026-02-13 08:03:07	2026-02-13 08:03:08	rate_limited	1117	0	\N	\N	\N	\N
46	9	{"items":[{"uoM":"KG","rate":"5%","hsCode":"3105.3000","discount":0,"extraTax":0,"quantity":1,"saleType":"3rd Schedule Goods","fedPayable":0,"furtherTax":0,"totalValues":273.22,"productDescription":"Dap","salesTaxApplicable":13.01,"valueSalesExcludingST":260.21,"salesTaxWithheldAtSource":0,"fixedNotifiedValueOrRetailPrice":260.21,"sroScheduleNo":"3rd Schedule goods","sroItemSerialNo":"51"}],"invoiceDate":"2026-02-13","invoiceType":"Sale Invoice","documentTypeId":1,"buyerAddress":"kahror pakka","invoiceRefNo":"3620291786117DI1770965899484","buyerProvince":"Punjab","sellerAddress":"KHAIR PUR ROAD PULL FAREED ABAD KAHROR PAKKA, Lodhran Kahror Pakka","sellerNTNCNIC":"3620291786117","sellerProvince":"Punjab","buyerBusinessName":"walk in customer","sellerBusinessName":"ZIA CORPORATION","buyerRegistrationType":"Unregistered","buyerNTNCNIC":""}		failed	2026-02-13 08:03:49	2026-02-13 08:03:50	rate_limited	1390	0	\N	\N	\N	\N
136	24	{"items":[{"uoM":"KG","rate":"5%","hsCode":"3105.3000","discount":0,"extraTax":0,"quantity":150,"saleType":"3rd Schedule Goods","fedPayable":0,"furtherTax":0,"totalValues":40983.08,"productDescription":"Fertilizer - DAP (3105.3000)","salesTaxApplicable":1951.58,"valueSalesExcludingST":39031.5,"salesTaxWithheldAtSource":0,"fixedNotifiedValueOrRetailPrice":39031.5,"sroScheduleNo":"3rd Schedule goods","sroItemSerialNo":"51"}],"invoiceDate":"2026-02-15","invoiceType":"Sale Invoice","documentTypeId":1,"buyerAddress":"Lahore, Pakistan","invoiceRefNo":"3620291786117DI1771170734016","buyerProvince":"Punjab","sellerAddress":"KHAIR PUR ROAD PULL FAREED ABAD KAHROR PAKKA, Lodhran Kahror Pakka","sellerNTNCNIC":"3620291786117","sellerProvince":"Punjab","buyerBusinessName":"Walk-in Customer","sellerBusinessName":"ZIA CORPORATION","buyerRegistrationType":"Unregistered","buyerNTNCNIC":""}	{\n\t\t\t\t\t\t\t\t\t\t\t"invoiceNumber": "3620291786117DIACPUZI011199",\n\t\t\t\t\t\t\t\t\t\t\t"dated": "2026-02-15 20:51:08",\n\t\t\t\t\t\t\t\t\t\t\t"validationResponse": {"statusCode":"00","status":"Valid","error":"","invoiceStatuses":[{"itemSNo":"1","statusCode":"00","status":"Valid","invoiceNo":"3620291786117DIACPUZI011199-1","errorCode":"","error":""}]}\n\n\t\t\t\t\t\t\t\t\t\t\t}	success	2026-02-15 15:53:27	2026-02-15 15:53:29	\N	1414	0	\N	\N	\N	fffc8431ec38ec6e9fee179775e6025f46f3cca17bf2e47f3bd03920ecde55a5
47	9	{"items":[{"uoM":"KG","rate":"5%","hsCode":"3105.3000","discount":0,"extraTax":0,"quantity":1,"saleType":"3rd Schedule Goods","fedPayable":0,"furtherTax":0,"totalValues":273.22,"productDescription":"Dap","salesTaxApplicable":13.01,"valueSalesExcludingST":260.21,"salesTaxWithheldAtSource":0,"fixedNotifiedValueOrRetailPrice":260.21,"sroScheduleNo":"3rd Schedule goods","sroItemSerialNo":"51"}],"invoiceDate":"2026-02-13","invoiceType":"Sale Invoice","documentTypeId":1,"buyerAddress":"kahror pakka","invoiceRefNo":"3620291786117DI1770965899484","buyerProvince":"Punjab","sellerAddress":"KHAIR PUR ROAD PULL FAREED ABAD KAHROR PAKKA, Lodhran Kahror Pakka","sellerNTNCNIC":"3620291786117","sellerProvince":"Punjab","buyerBusinessName":"walk in customer","sellerBusinessName":"ZIA CORPORATION","buyerRegistrationType":"Unregistered","buyerNTNCNIC":""}	{\n                    "dated": "2026-02-13 13:02:30",\n                    "validationResponse": {\n                        "statusCode": "01",\n                        "status": "Invalid",\n                        "errorCode": "500",\n                        "error": "Some thing went wrong. Please try again later."\n                    } \n                }	failed	2026-02-13 08:04:47	2026-02-13 08:04:48	validation_error	1127	0	\N	\N	\N	\N
48	9	{"items":[{"uoM":"KG","rate":"5%","hsCode":"3105.3000","discount":0,"extraTax":0,"quantity":1,"saleType":"3rd Schedule Goods","fedPayable":0,"furtherTax":0,"totalValues":273.22,"productDescription":"Dap","salesTaxApplicable":13.01,"valueSalesExcludingST":260.21,"salesTaxWithheldAtSource":0,"fixedNotifiedValueOrRetailPrice":260.21,"sroScheduleNo":"3rd Schedule goods","sroItemSerialNo":"51"}],"invoiceDate":"2026-02-13","invoiceType":"Sale Invoice","documentTypeId":1,"buyerAddress":"kahror pakka","invoiceRefNo":"3620291786117DI1770965899484","buyerProvince":"Punjab","sellerAddress":"KHAIR PUR ROAD PULL FAREED ABAD KAHROR PAKKA, Lodhran Kahror Pakka","sellerNTNCNIC":"3620291786117","sellerProvince":"Punjab","buyerBusinessName":"walk in customer","sellerBusinessName":"ZIA CORPORATION","buyerRegistrationType":"Unregistered","buyerNTNCNIC":""}		failed	2026-02-13 08:18:46	2026-02-13 08:18:47	rate_limited	1417	0	\N	\N	\N	\N
49	9	{"invoiceType":"Sale Invoice","invoiceDate":"2026-02-13","sellerNTNCNIC":"3620291786117","sellerBusinessName":"ZIA CORPORATION","sellerProvince":"Punjab","sellerAddress":"KHAIR PUR ROAD PULL FAREED ABAD KAHROR PAKKA, Lodhran Kahror Pakka","buyerNTNCNIC":null,"buyerBusinessName":"walk in customer","buyerProvince":"Punjab","buyerAddress":"kahror pakka","buyerRegistrationType":"Unregistered","invoiceRefNo":"3620291786117DI1770965899484","items":[{"hsCode":"3105.3000","productDescription":"Dap","rate":"5%","uoM":"Kilograms","quantity":1,"totalValues":273.22,"valueSalesExcludingST":260.21,"fixedNotifiedValueOrRetailPrice":260.21,"salesTaxApplicable":13.01,"salesTaxWithheldAtSource":0,"extraTax":0,"furtherTax":0,"sroScheduleNo":"3rd Schedule goods","fedPayable":0,"discount":0,"saleType":"Goods under 3rd Schedule","sroItemSerialNo":"51"}]}	{"fault":{"code":900901,"message":"Invalid Credentials","description":"Access failure for API: /di_data/v1, version: v1 status: (900901) - Invalid Credentials. Make sure you have given the correct access token"}}	failed	2026-02-13 08:26:53	2026-02-13 08:26:54	token_error	1025	0	\N	\N	\N	\N
50	9	{"invoiceType":"Sale Invoice","invoiceDate":"2026-02-13","sellerNTNCNIC":"3620291786117","sellerBusinessName":"ZIA CORPORATION","sellerProvince":"Punjab","sellerAddress":"KHAIR PUR ROAD PULL FAREED ABAD KAHROR PAKKA, Lodhran Kahror Pakka","buyerNTNCNIC":null,"buyerBusinessName":"walk in customer","buyerProvince":"Punjab","buyerAddress":"kahror pakka","buyerRegistrationType":"Unregistered","invoiceRefNo":"3620291786117DI1770965899484","items":[{"hsCode":"3105.3000","productDescription":"Dap","rate":"5%","uoM":"Kilograms","quantity":1,"totalValues":273.22,"valueSalesExcludingST":260.21,"fixedNotifiedValueOrRetailPrice":260.21,"salesTaxApplicable":13.01,"salesTaxWithheldAtSource":0,"extraTax":0,"furtherTax":0,"sroScheduleNo":"3rd Schedule goods","fedPayable":0,"discount":0,"saleType":"Goods under 3rd Schedule","sroItemSerialNo":"51"}]}	{"fault":{"code":900901,"message":"Invalid Credentials","description":"Access failure for API: /di_data/v1, version: v1 status: (900901) - Invalid Credentials. Make sure you have given the correct access token"}}	failed	2026-02-13 08:27:24	2026-02-13 08:27:25	token_error	943	1	\N	\N	\N	\N
51	9	{"invoiceType":"Sale Invoice","invoiceDate":"2026-02-13","sellerNTNCNIC":"3620291786117","sellerBusinessName":"ZIA CORPORATION","sellerProvince":"Punjab","sellerAddress":"KHAIR PUR ROAD PULL FAREED ABAD KAHROR PAKKA, Lodhran Kahror Pakka","buyerNTNCNIC":null,"buyerBusinessName":"walk in customer","buyerProvince":"Punjab","buyerAddress":"kahror pakka","buyerRegistrationType":"Unregistered","invoiceRefNo":"3620291786117DI1770965899484","items":[{"hsCode":"3105.3000","productDescription":"Dap","rate":"5%","uoM":"Kilograms","quantity":1,"totalValues":273.22,"valueSalesExcludingST":260.21,"fixedNotifiedValueOrRetailPrice":260.21,"salesTaxApplicable":13.01,"salesTaxWithheldAtSource":0,"extraTax":0,"furtherTax":0,"sroScheduleNo":"3rd Schedule goods","fedPayable":0,"discount":0,"saleType":"Goods under 3rd Schedule","sroItemSerialNo":"51"}]}	{"fault":{"code":900901,"message":"Invalid Credentials","description":"Access failure for API: /di_data/v1, version: v1 status: (900901) - Invalid Credentials. Make sure you have given the correct access token"}}	failed	2026-02-13 08:28:25	2026-02-13 08:28:26	token_error	1011	2	\N	\N	\N	\N
52	9	{"items":[{"uoM":"KG","rate":"5%","hsCode":"3105.3000","discount":0,"extraTax":0,"quantity":1,"saleType":"3rd Schedule Goods","fedPayable":0,"furtherTax":0,"totalValues":273.22,"productDescription":"Dap","salesTaxApplicable":13.01,"valueSalesExcludingST":260.21,"salesTaxWithheldAtSource":0,"fixedNotifiedValueOrRetailPrice":260.21,"sroScheduleNo":"3rd Schedule goods","sroItemSerialNo":"51"}],"invoiceDate":"2026-02-13","invoiceType":"Sale Invoice","documentTypeId":1,"buyerAddress":"kahror pakka","invoiceRefNo":"3620291786117DI1770965899484","buyerProvince":"Punjab","sellerAddress":"KHAIR PUR ROAD PULL FAREED ABAD KAHROR PAKKA, Lodhran Kahror Pakka","sellerNTNCNIC":"3620291786117","sellerProvince":"Punjab","buyerBusinessName":"walk in customer","sellerBusinessName":"ZIA CORPORATION","buyerRegistrationType":"Unregistered","buyerNTNCNIC":""}	{\n                    "dated": "2026-02-13 14:20:33",\n                    "validationResponse": {\n                        "statusCode": "01",\n                        "status": "Invalid",\n                        "errorCode": "500",\n                        "error": "Some thing went wrong. Please try again later."\n                    } \n                }	failed	2026-02-13 09:22:11	2026-02-13 09:22:13	validation_error	1458	0	\N	\N	\N	\N
53	9	{"items":[{"uoM":"KG","rate":"5%","hsCode":"3105.3000","discount":0,"extraTax":0,"quantity":1,"saleType":"3rd Schedule Goods","fedPayable":0,"furtherTax":0,"totalValues":273.22,"productDescription":"Dap","salesTaxApplicable":13.01,"valueSalesExcludingST":260.21,"salesTaxWithheldAtSource":0,"fixedNotifiedValueOrRetailPrice":260.21,"sroScheduleNo":"3rd Schedule goods","sroItemSerialNo":"51"}],"invoiceDate":"2026-02-13","invoiceType":"Sale Invoice","documentTypeId":1,"buyerAddress":"kahror pakka","invoiceRefNo":"3620291786117DI1770965899484","buyerProvince":"Punjab","sellerAddress":"KHAIR PUR ROAD PULL FAREED ABAD KAHROR PAKKA, Lodhran Kahror Pakka","sellerNTNCNIC":"3620291786117","sellerProvince":"Punjab","buyerBusinessName":"walk in customer","sellerBusinessName":"ZIA CORPORATION","buyerRegistrationType":"Unregistered","buyerNTNCNIC":""}		failed	2026-02-13 09:37:27	2026-02-13 09:37:28	rate_limited	1484	0	\N	\N	\N	\N
54	9	{"items":[{"uoM":"KG","rate":"5%","hsCode":"3105.3000","discount":0,"extraTax":0,"quantity":1,"saleType":"3rd Schedule Goods","fedPayable":0,"furtherTax":0,"totalValues":273.22,"productDescription":"Dap","salesTaxApplicable":13.01,"valueSalesExcludingST":260.21,"salesTaxWithheldAtSource":0,"fixedNotifiedValueOrRetailPrice":260.21,"sroScheduleNo":"3rd Schedule goods","sroItemSerialNo":"51"}],"invoiceDate":"2026-02-13","invoiceType":"Sale Invoice","documentTypeId":1,"buyerAddress":"kahror pakka","invoiceRefNo":"3620291786117DI1770965899484","buyerProvince":"Punjab","sellerAddress":"KHAIR PUR ROAD PULL FAREED ABAD KAHROR PAKKA, Lodhran Kahror Pakka","sellerNTNCNIC":"3620291786117","sellerProvince":"Punjab","buyerBusinessName":"walk in customer","sellerBusinessName":"ZIA CORPORATION","buyerRegistrationType":"Unregistered","buyerNTNCNIC":""}		failed	2026-02-13 09:37:36	2026-02-13 09:37:37	rate_limited	1043	0	\N	\N	\N	\N
55	9	{"items":[{"uoM":"KG","rate":"5%","hsCode":"3105.3000","discount":0,"extraTax":0,"quantity":1,"saleType":"3rd Schedule Goods","fedPayable":0,"furtherTax":0,"totalValues":273.22,"productDescription":"Dap","salesTaxApplicable":13.01,"valueSalesExcludingST":260.21,"salesTaxWithheldAtSource":0,"fixedNotifiedValueOrRetailPrice":260.21,"sroScheduleNo":"3rd Schedule goods","sroItemSerialNo":"51"}],"invoiceDate":"2026-02-13","invoiceType":"Sale Invoice","documentTypeId":1,"buyerAddress":"kahror pakka","invoiceRefNo":"3620291786117DI1770965899484","buyerProvince":"Punjab","sellerAddress":"KHAIR PUR ROAD PULL FAREED ABAD KAHROR PAKKA, Lodhran Kahror Pakka","sellerNTNCNIC":"3620291786117","sellerProvince":"Punjab","buyerBusinessName":"walk in customer","sellerBusinessName":"ZIA CORPORATION","buyerRegistrationType":"Unregistered","buyerNTNCNIC":""}		failed	2026-02-13 09:40:05	2026-02-13 09:40:06	rate_limited	1326	0	\N	\N	\N	\N
56	9	{"items":[{"uoM":"KG","rate":"5%","hsCode":"3105.3000","discount":0,"extraTax":0,"quantity":1,"saleType":"3rd Schedule Goods","fedPayable":0,"furtherTax":0,"totalValues":273.22,"productDescription":"Dap","salesTaxApplicable":13.01,"valueSalesExcludingST":260.21,"salesTaxWithheldAtSource":0,"fixedNotifiedValueOrRetailPrice":260.21,"sroScheduleNo":"3rd Schedule goods","sroItemSerialNo":"51"}],"invoiceDate":"2026-02-13","invoiceType":"Sale Invoice","documentTypeId":1,"buyerAddress":"kahror pakka","invoiceRefNo":"3620291786117DI1770965899484","buyerProvince":"Punjab","sellerAddress":"KHAIR PUR ROAD PULL FAREED ABAD KAHROR PAKKA, Lodhran Kahror Pakka","sellerNTNCNIC":"3620291786117","sellerProvince":"Punjab","buyerBusinessName":"JAWAD","sellerBusinessName":"ZIA CORPORATION","buyerRegistrationType":"Unregistered","buyerNTNCNIC":""}		failed	2026-02-13 09:49:16	2026-02-13 09:49:17	rate_limited	1215	0	\N	\N	\N	\N
137	25	{"items":[{"uoM":"KG","rate":"5%","hsCode":"3105.3000","discount":0,"extraTax":0,"quantity":200,"saleType":"3rd Schedule Goods","fedPayable":0,"furtherTax":0,"totalValues":54644.1,"productDescription":"Fertilizer - DAP (3105.3000)","salesTaxApplicable":2602.1,"valueSalesExcludingST":52042,"salesTaxWithheldAtSource":0,"fixedNotifiedValueOrRetailPrice":52042,"sroScheduleNo":"3rd Schedule goods","sroItemSerialNo":"51"}],"invoiceDate":"2026-02-15","invoiceType":"Sale Invoice","documentTypeId":1,"buyerAddress":"Lahore, Pakistan","invoiceRefNo":"3620291786117DI1771170734028","buyerProvince":"Punjab","sellerAddress":"KHAIR PUR ROAD PULL FAREED ABAD KAHROR PAKKA, Lodhran Kahror Pakka","sellerNTNCNIC":"3620291786117","sellerProvince":"Punjab","buyerBusinessName":"Walk-in Customer","sellerBusinessName":"ZIA CORPORATION","buyerRegistrationType":"Unregistered","buyerNTNCNIC":""}	{\n\t\t\t\t\t\t\t\t\t\t\t"invoiceNumber": "3620291786117DIACPUAI630424",\n\t\t\t\t\t\t\t\t\t\t\t"dated": "2026-02-15 20:52:34",\n\t\t\t\t\t\t\t\t\t\t\t"validationResponse": {"statusCode":"00","status":"Valid","error":"","invoiceStatuses":[{"itemSNo":"1","statusCode":"00","status":"Valid","invoiceNo":"3620291786117DIACPUAI630424-1","errorCode":"","error":""}]}\n\n\t\t\t\t\t\t\t\t\t\t\t}	success	2026-02-15 15:53:50	2026-02-15 15:53:52	\N	1750	0	\N	\N	\N	9bf05a92acf1eec716d939520d6214f6b1ff3f7c529a1d0de13cbd59b87f133a
57	9	{"items":[{"uoM":"KG","rate":"5%","hsCode":"3105.3000","discount":0,"extraTax":0,"quantity":1,"saleType":"3rd Schedule Goods","fedPayable":0,"furtherTax":0,"totalValues":273.22,"productDescription":"Dap","salesTaxApplicable":13.01,"valueSalesExcludingST":260.21,"salesTaxWithheldAtSource":0,"fixedNotifiedValueOrRetailPrice":260.21,"sroScheduleNo":"3rd Schedule goods","sroItemSerialNo":"51"}],"invoiceDate":"2026-02-13","invoiceType":"Sale Invoice","documentTypeId":1,"buyerAddress":"kahror pakka","invoiceRefNo":"3620291786117DI1770965899484","buyerProvince":"Punjab","sellerAddress":"KHAIR PUR ROAD PULL FAREED ABAD KAHROR PAKKA, Lodhran Kahror Pakka","sellerNTNCNIC":"3620291786117","sellerProvince":"Punjab","buyerBusinessName":"JAWAD","sellerBusinessName":"ZIA CORPORATION","buyerRegistrationType":"Unregistered","buyerNTNCNIC":""}		failed	2026-02-13 09:49:27	2026-02-13 09:49:28	rate_limited	1207	0	\N	\N	\N	\N
58	9	{"items":[{"uoM":"KG","rate":"5%","hsCode":"3105.3000","discount":0,"extraTax":0,"quantity":1,"saleType":"3rd Schedule Goods","fedPayable":0,"furtherTax":0,"totalValues":273.22,"productDescription":"Dap","salesTaxApplicable":13.01,"valueSalesExcludingST":260.21,"salesTaxWithheldAtSource":0,"fixedNotifiedValueOrRetailPrice":260.21,"sroScheduleNo":"3rd Schedule goods","sroItemSerialNo":"51"}],"invoiceDate":"2026-02-13","invoiceType":"Sale Invoice","documentTypeId":1,"buyerAddress":"kahror pakka","invoiceRefNo":"3620291786117DI1770965899484","buyerProvince":"Punjab","sellerAddress":"KHAIR PUR ROAD PULL FAREED ABAD KAHROR PAKKA, Lodhran Kahror Pakka","sellerNTNCNIC":"3620291786117","sellerProvince":"Punjab","buyerBusinessName":"JAWAD","sellerBusinessName":"ZIA CORPORATION","buyerRegistrationType":"Unregistered","buyerNTNCNIC":""}	{\n                    "dated": "2026-02-13 14:48:09",\n                    "validationResponse": {\n                        "statusCode": "01",\n                        "status": "Invalid",\n                        "errorCode": "500",\n                        "error": "Some thing went wrong. Please try again later."\n                    } \n                }	failed	2026-02-13 09:49:47	2026-02-13 09:49:48	validation_error	1117	1	\N	\N	\N	\N
59	9	{"items":[{"uoM":"KG","rate":"5%","hsCode":"3105.3000","discount":0,"extraTax":0,"quantity":1,"saleType":"3rd Schedule Goods","fedPayable":0,"furtherTax":0,"totalValues":273.22,"productDescription":"Dap","salesTaxApplicable":13.01,"valueSalesExcludingST":260.21,"salesTaxWithheldAtSource":0,"fixedNotifiedValueOrRetailPrice":260.21,"sroScheduleNo":"3rd Schedule goods","sroItemSerialNo":"51"}],"invoiceDate":"2026-02-13","invoiceType":"Sale Invoice","documentTypeId":1,"buyerAddress":"kahror pakka","invoiceRefNo":"3620291786117DI1770965899484","buyerProvince":"Punjab","sellerAddress":"KHAIR PUR ROAD PULL FAREED ABAD KAHROR PAKKA, Lodhran Kahror Pakka","sellerNTNCNIC":"3620291786117","sellerProvince":"Punjab","buyerBusinessName":"JAWAD","sellerBusinessName":"ZIA CORPORATION","buyerRegistrationType":"Unregistered","buyerNTNCNIC":""}		failed	2026-02-13 09:50:48	2026-02-13 09:50:50	rate_limited	1157	2	\N	\N	\N	\N
60	9	{"items":[{"uoM":"KG","rate":"5%","hsCode":"3105.3000","discount":0,"extraTax":0,"quantity":1,"saleType":"3rd Schedule Goods","fedPayable":0,"furtherTax":0,"totalValues":273.22,"productDescription":"Dap","salesTaxApplicable":13.01,"valueSalesExcludingST":260.21,"salesTaxWithheldAtSource":0,"fixedNotifiedValueOrRetailPrice":260.21,"sroScheduleNo":"3rd Schedule goods","sroItemSerialNo":"51"}],"invoiceDate":"2026-02-13","invoiceType":"Sale Invoice","documentTypeId":1,"buyerAddress":"kahror pakka","invoiceRefNo":"3620291786117DI1770965899484","buyerProvince":"Punjab","sellerAddress":"KHAIR PUR ROAD PULL FAREED ABAD KAHROR PAKKA, Lodhran Kahror Pakka","sellerNTNCNIC":"3620291786117","sellerProvince":"Punjab","buyerBusinessName":"CONFIRM","sellerBusinessName":"ZIA CORPORATION","buyerRegistrationType":"Unregistered","buyerNTNCNIC":""}	{"note":"FBR accepted - confirmed via FBR portal PDF","fbr_invoice_number":"3620291786117DIACNOZD744375"}	success	2026-02-13 09:53:05	2026-02-13 09:53:06	\N	1355	0	\N	\N	\N	\N
61	9	{"items":[{"uoM":"KG","rate":"5%","hsCode":"3105.3000","discount":0,"extraTax":0,"quantity":1,"saleType":"3rd Schedule Goods","fedPayable":0,"furtherTax":0,"totalValues":273.22,"productDescription":"Dap","salesTaxApplicable":13.01,"valueSalesExcludingST":260.21,"salesTaxWithheldAtSource":0,"fixedNotifiedValueOrRetailPrice":260.21,"sroScheduleNo":"3rd Schedule goods","sroItemSerialNo":"51"}],"invoiceDate":"2026-02-13","invoiceType":"Sale Invoice","documentTypeId":1,"buyerAddress":"kahror pakka","invoiceRefNo":"3620291786117DI1770965899484","buyerProvince":"Punjab","sellerAddress":"KHAIR PUR ROAD PULL FAREED ABAD KAHROR PAKKA, Lodhran Kahror Pakka","sellerNTNCNIC":"3620291786117","sellerProvince":"Punjab","buyerBusinessName":"CONFIRM","sellerBusinessName":"ZIA CORPORATION","buyerRegistrationType":"Unregistered","buyerNTNCNIC":""}	{"note":"FBR accepted - confirmed via FBR portal PDF","fbr_invoice_number":"3620291786117DIACNOYW946545"}	success	2026-02-13 09:53:09	2026-02-13 09:53:10	\N	1177	0	\N	\N	\N	\N
62	9	{"items":[{"uoM":"KG","rate":"5%","hsCode":"3105.3000","discount":0,"extraTax":0,"quantity":1,"saleType":"3rd Schedule Goods","fedPayable":0,"furtherTax":0,"totalValues":273.22,"productDescription":"Dap","salesTaxApplicable":13.01,"valueSalesExcludingST":260.21,"salesTaxWithheldAtSource":0,"fixedNotifiedValueOrRetailPrice":260.21,"sroScheduleNo":"3rd Schedule goods","sroItemSerialNo":"51"}],"invoiceDate":"2026-02-13","invoiceType":"Sale Invoice","documentTypeId":1,"buyerAddress":"kahror pakka","invoiceRefNo":"3620291786117DI1770965899484","buyerProvince":"Punjab","sellerAddress":"KHAIR PUR ROAD PULL FAREED ABAD KAHROR PAKKA, Lodhran Kahror Pakka","sellerNTNCNIC":"3620291786117","sellerProvince":"Punjab","buyerBusinessName":"CONFIRM","sellerBusinessName":"ZIA CORPORATION","buyerRegistrationType":"Unregistered","buyerNTNCNIC":""}		failed	2026-02-13 09:53:36	2026-02-13 09:53:37	rate_limited	1183	1	\N	\N	\N	\N
63	9	{"items":[{"uoM":"KG","rate":"5%","hsCode":"3105.3000","discount":0,"extraTax":0,"quantity":1,"saleType":"3rd Schedule Goods","fedPayable":0,"furtherTax":0,"totalValues":273.22,"productDescription":"Dap","salesTaxApplicable":13.01,"valueSalesExcludingST":260.21,"salesTaxWithheldAtSource":0,"fixedNotifiedValueOrRetailPrice":260.21,"sroScheduleNo":"3rd Schedule goods","sroItemSerialNo":"51"}],"invoiceDate":"2026-02-13","invoiceType":"Sale Invoice","documentTypeId":1,"buyerAddress":"kahror pakka","invoiceRefNo":"3620291786117DI1770965899484","buyerProvince":"Punjab","sellerAddress":"KHAIR PUR ROAD PULL FAREED ABAD KAHROR PAKKA, Lodhran Kahror Pakka","sellerNTNCNIC":"3620291786117","sellerProvince":"Punjab","buyerBusinessName":"CONFIRM","sellerBusinessName":"ZIA CORPORATION","buyerRegistrationType":"Unregistered","buyerNTNCNIC":""}		failed	2026-02-13 09:54:37	2026-02-13 09:54:39	rate_limited	1130	2	\N	\N	\N	\N
64	9	{"items":[{"uoM":"KG","rate":"5%","hsCode":"3105.3000","discount":0,"extraTax":0,"quantity":1,"saleType":"3rd Schedule Goods","fedPayable":0,"furtherTax":0,"totalValues":273.22,"productDescription":"Dap","salesTaxApplicable":13.01,"valueSalesExcludingST":260.21,"salesTaxWithheldAtSource":0,"fixedNotifiedValueOrRetailPrice":260.21,"sroScheduleNo":"3rd Schedule goods","sroItemSerialNo":"51"}],"invoiceDate":"2026-02-13","invoiceType":"Sale Invoice","documentTypeId":1,"buyerAddress":"kahror pakka","invoiceRefNo":"3620291786117DI1770965899484","buyerProvince":"Punjab","sellerAddress":"KHAIR PUR ROAD PULL FAREED ABAD KAHROR PAKKA, Lodhran Kahror Pakka","sellerNTNCNIC":"3620291786117","sellerProvince":"Punjab","buyerBusinessName":"NISAR","sellerBusinessName":"ZIA CORPORATION","buyerRegistrationType":"Unregistered","buyerNTNCNIC":""}		failed	2026-02-13 09:57:12	2026-02-13 09:57:13	rate_limited	1151	0	\N	\N	\N	\N
65	9	{"items":[{"uoM":"KG","rate":"5%","hsCode":"3105.3000","discount":0,"extraTax":0,"quantity":1,"saleType":"3rd Schedule Goods","fedPayable":0,"furtherTax":0,"totalValues":273.22,"productDescription":"Dap","salesTaxApplicable":13.01,"valueSalesExcludingST":260.21,"salesTaxWithheldAtSource":0,"fixedNotifiedValueOrRetailPrice":260.21,"sroScheduleNo":"3rd Schedule goods","sroItemSerialNo":"51"}],"invoiceDate":"2026-02-13","invoiceType":"Sale Invoice","documentTypeId":1,"buyerAddress":"kahror pakka","invoiceRefNo":"3620291786117DI1770965899484","buyerProvince":"Punjab","sellerAddress":"KHAIR PUR ROAD PULL FAREED ABAD KAHROR PAKKA, Lodhran Kahror Pakka","sellerNTNCNIC":"3620291786117","sellerProvince":"Punjab","buyerBusinessName":"NISAR","sellerBusinessName":"ZIA CORPORATION","buyerRegistrationType":"Unregistered","buyerNTNCNIC":""}		failed	2026-02-13 09:57:43	2026-02-13 09:57:44	rate_limited	1093	1	\N	\N	\N	\N
66	9	{"items":[{"uoM":"KG","rate":"5%","hsCode":"3105.3000","discount":0,"extraTax":0,"quantity":1,"saleType":"3rd Schedule Goods","fedPayable":0,"furtherTax":0,"totalValues":273.22,"productDescription":"Dap","salesTaxApplicable":13.01,"valueSalesExcludingST":260.21,"salesTaxWithheldAtSource":0,"fixedNotifiedValueOrRetailPrice":260.21,"sroScheduleNo":"3rd Schedule goods","sroItemSerialNo":"51"}],"invoiceDate":"2026-02-13","invoiceType":"Sale Invoice","documentTypeId":1,"buyerAddress":"kahror pakka","invoiceRefNo":"3620291786117DI1770965899484","buyerProvince":"Punjab","sellerAddress":"KHAIR PUR ROAD PULL FAREED ABAD KAHROR PAKKA, Lodhran Kahror Pakka","sellerNTNCNIC":"3620291786117","sellerProvince":"Punjab","buyerBusinessName":"NISAR","sellerBusinessName":"ZIA CORPORATION","buyerRegistrationType":"Unregistered","buyerNTNCNIC":""}	{"note":"FBR returned 200 OK with empty body - invoice accepted","generated_fbr_number":"3620291786117DI1770976705355"}	success	2026-02-13 09:58:23	2026-02-13 09:58:25	\N	1527	0	\N	\N	\N	\N
67	9	{"items":[{"uoM":"KG","rate":"5%","hsCode":"3105.3000","discount":0,"extraTax":0,"quantity":1,"saleType":"3rd Schedule Goods","fedPayable":0,"furtherTax":0,"totalValues":273.22,"productDescription":"Dap","salesTaxApplicable":13.01,"valueSalesExcludingST":260.21,"salesTaxWithheldAtSource":0,"fixedNotifiedValueOrRetailPrice":260.21,"sroScheduleNo":"3rd Schedule goods","sroItemSerialNo":"51"}],"invoiceDate":"2026-02-13","invoiceType":"Sale Invoice","documentTypeId":1,"buyerAddress":"kahror pakka","invoiceRefNo":"3620291786117DI1770965899484","buyerProvince":"Punjab","sellerAddress":"KHAIR PUR ROAD PULL FAREED ABAD KAHROR PAKKA, Lodhran Kahror Pakka","sellerNTNCNIC":"3620291786117","sellerProvince":"Punjab","buyerBusinessName":"NISAR","sellerBusinessName":"ZIA CORPORATION","buyerRegistrationType":"Unregistered","buyerNTNCNIC":""}		failed	2026-02-13 09:58:44	2026-02-13 09:58:46	rate_limited	1283	2	\N	\N	\N	\N
68	9	{"items":[{"uoM":"KG","rate":"5%","hsCode":"3105.3000","discount":0,"extraTax":0,"quantity":1,"saleType":"3rd Schedule Goods","fedPayable":0,"furtherTax":0,"totalValues":273.22,"productDescription":"Dap","salesTaxApplicable":13.01,"valueSalesExcludingST":260.21,"salesTaxWithheldAtSource":0,"fixedNotifiedValueOrRetailPrice":260.21,"sroScheduleNo":"3rd Schedule goods","sroItemSerialNo":"51"}],"invoiceDate":"2026-02-13","invoiceType":"Sale Invoice","documentTypeId":1,"buyerAddress":"kahror pakka","invoiceRefNo":"3620291786117DI1770965899484","buyerProvince":"Punjab","sellerAddress":"KHAIR PUR ROAD PULL FAREED ABAD KAHROR PAKKA, Lodhran Kahror Pakka","sellerNTNCNIC":"3620291786117","sellerProvince":"Punjab","buyerBusinessName":"NISAR","sellerBusinessName":"ZIA CORPORATION","buyerRegistrationType":"Unregistered","buyerNTNCNIC":""}	{"note":"FBR returned 200 OK with empty body - invoice accepted","generated_fbr_number":"3620291786117DI1770976737780"}	success	2026-02-13 09:58:56	2026-02-13 09:58:57	\N	1078	0	\N	\N	\N	\N
69	9	{"items":[{"uoM":"KG","rate":"5%","hsCode":"3105.3000","discount":0,"extraTax":0,"quantity":1,"saleType":"3rd Schedule Goods","fedPayable":0,"furtherTax":0,"totalValues":273.22,"productDescription":"Dap","salesTaxApplicable":13.01,"valueSalesExcludingST":260.21,"salesTaxWithheldAtSource":0,"fixedNotifiedValueOrRetailPrice":260.21,"sroScheduleNo":"3rd Schedule goods","sroItemSerialNo":"51"}],"invoiceDate":"2026-02-13","invoiceType":"Sale Invoice","documentTypeId":1,"buyerAddress":"kahror pakka","invoiceRefNo":"3620291786117DI1770965899484","buyerProvince":"Punjab","sellerAddress":"KHAIR PUR ROAD PULL FAREED ABAD KAHROR PAKKA, Lodhran Kahror Pakka","sellerNTNCNIC":"3620291786117","sellerProvince":"Punjab","buyerBusinessName":"NISAR","sellerBusinessName":"ZIA CORPORATION","buyerRegistrationType":"Unregistered","buyerNTNCNIC":""}	{\n                    "dated": "2026-02-13 14:57:45",\n                    "validationResponse": {\n                        "statusCode": "01",\n                        "status": "Invalid",\n                        "errorCode": "500",\n                        "error": "Some thing went wrong. Please try again later."\n                    } \n                }	failed	2026-02-13 09:58:59	2026-02-13 09:59:00	validation_error	1231	0	\N	\N	\N	\N
70	10	{"items":[{"uoM":"KG","rate":"5%","hsCode":"3105.3000","discount":0,"extraTax":0,"quantity":2,"saleType":"3rd Schedule Goods","fedPayable":0,"furtherTax":0,"totalValues":273.22,"productDescription":"Dap","salesTaxApplicable":13.01,"valueSalesExcludingST":260.21,"salesTaxWithheldAtSource":0,"fixedNotifiedValueOrRetailPrice":260.21,"sroScheduleNo":"3rd Schedule Goods","sroItemSerialNo":"51"}],"invoiceDate":"2026-02-14","invoiceType":"Sale Invoice","documentTypeId":1,"buyerAddress":"kahror pakka","invoiceRefNo":"3620291786117DI1771050183903","buyerProvince":"Punjab","sellerAddress":"KHAIR PUR ROAD PULL FAREED ABAD KAHROR PAKKA, Lodhran Kahror Pakka","sellerNTNCNIC":"3620291786117","sellerProvince":"Punjab","buyerBusinessName":"WALK IN CUSTOMER","sellerBusinessName":"ZIA CORPORATION","buyerRegistrationType":"Unregistered","buyerNTNCNIC":""}	{"note":"FBR returned 200 OK with empty body - status unknown, needs manual verification","http_code":200}	pending_verification	2026-02-14 06:23:37	2026-02-14 06:23:38	ambiguous_response	1447	0	production	unknown	1465	\N
71	12	{"items":[{"uoM":"KG","rate":"5%","hsCode":"3105.3000","discount":0,"extraTax":0,"quantity":1,"saleType":"3rd Schedule Goods","fedPayable":0,"furtherTax":0,"totalValues":273.22,"productDescription":"Dap","salesTaxApplicable":13.01,"valueSalesExcludingST":260.21,"salesTaxWithheldAtSource":0,"fixedNotifiedValueOrRetailPrice":260.21,"sroScheduleNo":"3rd Schedule Goods","sroItemSerialNo":"51"}],"invoiceDate":"2026-02-14","invoiceType":"Sale Invoice","documentTypeId":1,"buyerAddress":"Kahror pakka","invoiceRefNo":"36381144DI1771062897784","buyerProvince":"Punjab","sellerAddress":"KHAIR PUR ROAD PULL FAREED ABAD KAHROR PAKKA, Lodhran Kahror Pakka","sellerNTNCNIC":"36381144","sellerProvince":"Punjab","buyerBusinessName":"walk in cutomer","sellerBusinessName":"ZIA CORPORATION","buyerRegistrationType":"Unregistered","buyerNTNCNIC":""}	{\n\t\t\t\t\t\t\t\t\t\t\t"dated":"2026-02-14 15:19:41",\n\t\t\t\t\t\t\t\t\t\t\t"validationResponse": {\n\t\t\t\t\t\t\t\t\t\t\t"statusCode" : "01",\n\t\t\t\t\t\t\t\t\t\t\t"status": "Invalid",\n\t\t\t\t\t\t\t\t\t\t\t"errorCode":"0401",\n\t\t\t\t\t\t\t\t\t\t\t"error":"Unauthorized access: Provided seller registration number is not 13 digits (CNIC) or 7 digits (NTN) or the authorized token does not exist against seller registration number"\n\t\t\t\t\t\t\t\t\t\t\t}	failed	2026-02-14 10:20:56	2026-02-14 10:20:58	token_error	1429	0	production	authentication	1466	\N
72	12	{"items":[{"uoM":"KG","rate":"5%","hsCode":"3105.3000","discount":0,"extraTax":0,"quantity":1,"saleType":"3rd Schedule Goods","fedPayable":0,"furtherTax":0,"totalValues":273.22,"productDescription":"Dap","salesTaxApplicable":13.01,"valueSalesExcludingST":260.21,"salesTaxWithheldAtSource":0,"fixedNotifiedValueOrRetailPrice":260.21,"sroScheduleNo":"3rd Schedule Goods","sroItemSerialNo":"51"}],"invoiceDate":"2026-02-14","invoiceType":"Sale Invoice","documentTypeId":1,"buyerAddress":"Kahror pakka","invoiceRefNo":"36381144DI1771062897784","buyerProvince":"Punjab","sellerAddress":"KHAIR PUR ROAD PULL FAREED ABAD KAHROR PAKKA, Lodhran Kahror Pakka","sellerNTNCNIC":"3638114","sellerProvince":"Punjab","buyerBusinessName":"walk in cutomer","sellerBusinessName":"ZIA CORPORATION","buyerRegistrationType":"Unregistered","buyerNTNCNIC":""}	{"note":"FBR returned 200 OK with empty body - status unknown, needs manual verification","http_code":200}	pending_verification	2026-02-14 10:25:39	2026-02-14 10:25:40	ambiguous_response	1162	0	production	unknown	1195	\N
73	11	{"items":[{"uoM":"KG","rate":"5%","hsCode":"3105.3000","discount":0,"extraTax":0,"quantity":3,"saleType":"3rd Schedule Goods","fedPayable":0,"furtherTax":0,"totalValues":273.22,"productDescription":"Dap","salesTaxApplicable":13.01,"valueSalesExcludingST":260.21,"salesTaxWithheldAtSource":0,"fixedNotifiedValueOrRetailPrice":260.21,"sroScheduleNo":"3rd Schedule Goods","sroItemSerialNo":"51"}],"invoiceDate":"2026-02-14","invoiceType":"Sale Invoice","documentTypeId":1,"buyerAddress":"Kahror pakka","invoiceRefNo":"36381144DI1771055182422","buyerProvince":"Punjab","sellerAddress":"KHAIR PUR ROAD PULL FAREED ABAD KAHROR PAKKA, Lodhran Kahror Pakka","sellerNTNCNIC":"3638114","sellerProvince":"Punjab","buyerBusinessName":"walk in cutomer","sellerBusinessName":"ZIA CORPORATION","buyerRegistrationType":"Unregistered","buyerNTNCNIC":""}	{"note":"FBR returned 200 OK with empty body - status unknown, needs manual verification","http_code":200}	pending_verification	2026-02-14 10:25:52	2026-02-14 10:25:53	ambiguous_response	1127	0	production	unknown	1152	\N
74	13	{"items":[{"uoM":"KG","rate":"5%","hsCode":"3105.3000","discount":0,"extraTax":0,"quantity":3,"saleType":"3rd Schedule goods","fedPayable":0,"furtherTax":0,"totalValues":273.22,"productDescription":"Dap","salesTaxApplicable":13.01,"valueSalesExcludingST":260.21,"salesTaxWithheldAtSource":0,"fixedNotifiedValueOrRetailPrice":260.21,"sroScheduleNo":"3rd Schedule goods","sroItemSerialNo":"51"}],"invoiceDate":"2026-02-14","invoiceType":"Sale Invoice","documentTypeId":1,"buyerAddress":"lodhran","invoiceRefNo":"36381144DI1771073421293","buyerProvince":"Punjab","sellerAddress":"KHAIR PUR ROAD PULL FAREED ABAD KAHROR PAKKA, Lodhran Kahror Pakka","sellerNTNCNIC":"3620291786117","sellerProvince":"Punjab","buyerBusinessName":"Abrar Ahmad","sellerBusinessName":"ZIA CORPORATION","buyerRegistrationType":"Unregistered","buyerNTNCNIC":""}	{"note":"FBR returned error 500 \\"Something went wrong\\" - invoice may be accepted, needs manual verification","original_response":{"dated":"2026-02-14 17:51:58","validationResponse":{"statusCode":"01","status":"Invalid","errorCode":"500","error":"Some thing went wrong. Please try again later."}}}	pending_verification	2026-02-14 12:53:38	2026-02-14 12:53:39	ambiguous_response	1350	0	\N	\N	\N	\N
75	13	{"items":[{"uoM":"KG","rate":"5%","hsCode":"3105.3000","discount":0,"extraTax":0,"quantity":3,"saleType":"3rd Schedule Goods","fedPayable":0,"furtherTax":0,"totalValues":819.66,"productDescription":"Dap","salesTaxApplicable":39.03,"valueSalesExcludingST":780.63,"salesTaxWithheldAtSource":0,"fixedNotifiedValueOrRetailPrice":260.21,"sroScheduleNo":"3rd Schedule Goods","sroItemSerialNo":"51"}],"invoiceDate":"2026-02-14","invoiceType":"Sale Invoice","documentTypeId":1,"buyerAddress":"lodhran","invoiceRefNo":"36381144DI1771073421293","buyerProvince":"Punjab","sellerAddress":"KHAIR PUR ROAD PULL FAREED ABAD KAHROR PAKKA, Lodhran Kahror Pakka","sellerNTNCNIC":"3620291786117","sellerProvince":"Punjab","buyerBusinessName":"Abrar Ahmad","sellerBusinessName":"ZIA CORPORATION","buyerRegistrationType":"Unregistered","buyerNTNCNIC":""}	{"note":"FBR returned 200 OK with empty body - status unknown, needs manual verification","http_code":200}	pending_verification	2026-02-14 13:04:26	2026-02-14 13:04:27	ambiguous_response	1399	0	\N	\N	\N	\N
76	13	{"items":[{"uoM":"KG","rate":"5%","hsCode":"3105.3000","discount":0,"extraTax":0,"quantity":3,"saleType":"3rd Schedule Goods","fedPayable":0,"furtherTax":0,"totalValues":819.66,"productDescription":"Dap","salesTaxApplicable":39.03,"valueSalesExcludingST":780.63,"salesTaxWithheldAtSource":0,"fixedNotifiedValueOrRetailPrice":260.21,"sroScheduleNo":"3rd Schedule Goods","sroItemSerialNo":"51"}],"invoiceDate":"2026-02-14","invoiceType":"Sale Invoice","documentTypeId":1,"buyerAddress":"lodhran","invoiceRefNo":"36381144DI1771073421293","buyerProvince":"Punjab","sellerAddress":"KHAIR PUR ROAD PULL FAREED ABAD KAHROR PAKKA, Lodhran Kahror Pakka","sellerNTNCNIC":"3620291786117","sellerProvince":"Punjab","buyerBusinessName":"Abrar Ahmad","sellerBusinessName":"ZIA CORPORATION","buyerRegistrationType":"Unregistered","buyerNTNCNIC":""}	{"note":"FBR returned 200 OK with empty body - status unknown, needs manual verification","http_code":200}	pending_verification	2026-02-14 13:09:51	2026-02-14 13:09:52	ambiguous_response	1148	0	\N	\N	\N	\N
77	13	{"items":[{"uoM":"KG","rate":"5%","hsCode":"3105.3000","discount":0,"extraTax":0,"quantity":3,"saleType":"3rd Schedule Goods","fedPayable":0,"furtherTax":0,"totalValues":819.66,"productDescription":"Dap","salesTaxApplicable":39.03,"valueSalesExcludingST":780.63,"salesTaxWithheldAtSource":0,"fixedNotifiedValueOrRetailPrice":260.21,"sroScheduleNo":"3rd Schedule Goods","sroItemSerialNo":"51"}],"invoiceDate":"2026-02-14","invoiceType":"Sale Invoice","documentTypeId":1,"buyerAddress":"lodhran","invoiceRefNo":"3620291786117DI1771073421293","buyerProvince":"Punjab","sellerAddress":"KHAIR PUR ROAD PULL FAREED ABAD KAHROR PAKKA, Lodhran Kahror Pakka","sellerNTNCNIC":"3620291786117","sellerProvince":"Punjab","buyerBusinessName":"Abrar Ahmad","sellerBusinessName":"ZIA CORPORATION","buyerRegistrationType":"Unregistered","buyerNTNCNIC":""}	{"note":"FBR returned 200 OK with empty body - status unknown, needs manual verification","http_code":200}	pending_verification	2026-02-14 13:18:01	2026-02-14 13:18:02	ambiguous_response	1426	0	\N	\N	\N	\N
78	13	{"items":[{"uoM":"KG","rate":"5%","hsCode":"3105.3000","discount":0,"extraTax":0,"quantity":3,"saleType":"3rd Schedule Goods","fedPayable":0,"furtherTax":0,"totalValues":819.66,"productDescription":"Dap","salesTaxApplicable":39.03,"valueSalesExcludingST":780.63,"salesTaxWithheldAtSource":0,"fixedNotifiedValueOrRetailPrice":260.21,"sroScheduleNo":"3rd Schedule Goods","sroItemSerialNo":"51"}],"invoiceDate":"2026-02-14","invoiceType":"Sale Invoice","documentTypeId":1,"buyerAddress":"lodhran","invoiceRefNo":"3620291786117DI1771073421293","buyerProvince":"Punjab","sellerAddress":"KHAIR PUR ROAD PULL FAREED ABAD KAHROR PAKKA, Lodhran Kahror Pakka","sellerNTNCNIC":"3620291786117","sellerProvince":"Punjab","buyerBusinessName":"Abrar Ahmad","sellerBusinessName":"ZIA CORPORATION","buyerRegistrationType":"Unregistered","buyerNTNCNIC":""}	{"note":"FBR returned 200 OK with empty body - status unknown, needs manual verification","http_code":200,"response_headers":{"access-control-allow-origin":"*","access-control-allow-methods":"POST, GET","x-content-type-options":"nosniff","pragma":"no-cache","x-frame-options":"DENY","access-control-allow-headers":"authorization,Access-Control-Allow-Origin,Content-Type,SOAPAction","referrer-policy":"origin","strict-transport-security":"max-age=31536000; includeSubDomains; preload","cache-control":"no-cache, no-store, must-revalidate","content-security-policy":"default-src 'self' *.appdynamics.com *.eum-appdynamics.com *.youtube.com *  'unsafe-inline' 'unsafe-eval'","feature-policy":"microphone 'none'; geolocation 'none'","set-cookie":"cookiesession1=678B28ED342F544906C277086F8DBC05;Expires=Sun, 14 Feb 2027 13:24:20 GMT;Path=\\/;HttpOnly","expires":"Thu, 01 Jan 1970 00:00:00 GMT","x-xss-protection":"1; mode=block","content-type":"application\\/json; charset=UTF-8","date":"Sat, 14 Feb 2026 13:23:54 GMT","content-length":"0"},"server_ip":"103.125.60.111","total_time_sec":1.492587}	pending_verification	2026-02-14 13:24:19	2026-02-14 13:24:20	ambiguous_response	1529	0	\N	\N	\N	\N
79	13	{"items":[{"uoM":"KG","rate":"5%","hsCode":"3105.3000","discount":0,"extraTax":0,"quantity":3,"saleType":"3rd Schedule Goods","fedPayable":0,"furtherTax":0,"totalValues":273.22,"productDescription":"Dap","salesTaxApplicable":13.01,"valueSalesExcludingST":260.21,"salesTaxWithheldAtSource":0,"fixedNotifiedValueOrRetailPrice":260.21,"sroScheduleNo":"3rd Schedule goods","sroItemSerialNo":"51"}],"invoiceDate":"2026-02-14","invoiceType":"Sale Invoice","documentTypeId":1,"buyerAddress":"lodhran","invoiceRefNo":"3638114-4DI1771073421293","buyerProvince":"Punjab","sellerAddress":"KHAIR PUR ROAD PULL FAREED ABAD KAHROR PAKKA, Lodhran Kahror Pakka","sellerNTNCNIC":"3620291786117","sellerProvince":"Punjab","buyerBusinessName":"Abrar Ahmad","sellerBusinessName":"ZIA CORPORATION","buyerRegistrationType":"Unregistered","buyerNTNCNIC":""}	{"note":"FBR returned 200 OK with empty body - status unknown, needs manual verification","http_code":200,"response_headers":{"access-control-allow-origin":"*","access-control-allow-methods":"POST, GET","x-content-type-options":"nosniff","pragma":"no-cache","x-frame-options":"DENY","access-control-allow-headers":"authorization,Access-Control-Allow-Origin,Content-Type,SOAPAction","referrer-policy":"origin","strict-transport-security":"max-age=31536000; includeSubDomains; preload","cache-control":"no-cache, no-store, must-revalidate","content-security-policy":"default-src 'self' *.appdynamics.com *.eum-appdynamics.com *.youtube.com *  'unsafe-inline' 'unsafe-eval'","feature-policy":"microphone 'none'; geolocation 'none'","set-cookie":"cookiesession1=678B28EDF9FEA19A6BC108A7159BC67B;Expires=Sun, 14 Feb 2027 13:29:00 GMT;Path=\\/;HttpOnly","expires":"Thu, 01 Jan 1970 00:00:00 GMT","x-xss-protection":"1; mode=block","content-type":"application\\/json; charset=UTF-8","date":"Sat, 14 Feb 2026 13:28:05 GMT","content-length":"0"},"server_ip":"103.125.60.111","total_time_sec":1.444122}	pending_verification	2026-02-14 13:28:59	2026-02-14 13:29:01	ambiguous_response	1462	0	\N	\N	\N	\N
80	13	{"items":[{"uoM":"KG","rate":"5%","hsCode":"3105.3000","discount":0,"extraTax":0,"quantity":3,"saleType":"3rd Schedule Goods","fedPayable":0,"furtherTax":0,"totalValues":273.22,"productDescription":"Dap","salesTaxApplicable":13.01,"valueSalesExcludingST":260.21,"salesTaxWithheldAtSource":0,"fixedNotifiedValueOrRetailPrice":260.21,"sroScheduleNo":"3rd Schedule goods","sroItemSerialNo":"51"}],"invoiceDate":"2026-02-14","invoiceType":"Sale Invoice","documentTypeId":1,"buyerAddress":"lodhran","invoiceRefNo":"3638114-4DI1771073421293","buyerProvince":"Punjab","sellerAddress":"KHAIR PUR ROAD PULL FAREED ABAD KAHROR PAKKA, Lodhran Kahror Pakka","sellerNTNCNIC":"3620291786117","sellerProvince":"Punjab","buyerBusinessName":"Abrar Ahmad","sellerBusinessName":"ZIA CORPORATION","buyerRegistrationType":"Unregistered","buyerNTNCNIC":""}	Not Found	failed	2026-02-14 13:33:20	2026-02-14 13:33:20	payload_error	65	0	\N	\N	\N	\N
81	14	{"items":[{"uoM":"KG","rate":"5%","hsCode":"3105.3000","discount":0,"extraTax":0,"quantity":1,"saleType":"3rd Schedule Goods","fedPayable":0,"furtherTax":0,"totalValues":52.5,"productDescription":"Dap","salesTaxApplicable":2.5,"valueSalesExcludingST":50,"salesTaxWithheldAtSource":0,"fixedNotifiedValueOrRetailPrice":50,"sroScheduleNo":"3rd Schedule goods","sroItemSerialNo":"51"}],"invoiceDate":"2026-02-14","invoiceType":"Sale Invoice","documentTypeId":1,"buyerAddress":"lodhran","invoiceRefNo":"3620291786117DI1771076887931","buyerProvince":"Punjab","sellerAddress":"KHAIR PUR ROAD PULL FAREED ABAD KAHROR PAKKA, Lodhran Kahror Pakka","sellerNTNCNIC":"3620291786117","sellerProvince":"Punjab","buyerBusinessName":"rayan","sellerBusinessName":"ZIA CORPORATION","buyerRegistrationType":"Unregistered","buyerNTNCNIC":""}	{\n\t\t\t\t\t\t\t\t\t\t\t"invoiceNumber": "3620291786117DIACOSDC566199",\n\t\t\t\t\t\t\t\t\t\t\t"dated": "2026-02-14 18:55:54",\n\t\t\t\t\t\t\t\t\t\t\t"validationResponse": {"statusCode":"00","status":"Valid","error":"","invoiceStatuses":[{"itemSNo":"1","statusCode":"00","status":"Valid","invoiceNo":"3620291786117DIACOSDC566199-1","errorCode":"","error":""}]}\n\n\t\t\t\t\t\t\t\t\t\t\t}	success	2026-02-14 13:55:54	2026-02-14 13:55:56	\N	1483	0	production	\N	1503	\N
138	21	{"items":[{"uoM":"KG","rate":"5%","hsCode":"3105.3000","discount":0,"extraTax":0,"quantity":1,"saleType":"3rd Schedule Goods","fedPayable":0,"furtherTax":0,"totalValues":1092.88,"productDescription":"Dap","salesTaxApplicable":52.04,"valueSalesExcludingST":1040.84,"salesTaxWithheldAtSource":0,"fixedNotifiedValueOrRetailPrice":1040.84,"sroScheduleNo":"3rd Schedule goods","sroItemSerialNo":"51"}],"invoiceDate":"2026-02-15","invoiceType":"Sale Invoice","documentTypeId":1,"buyerAddress":"Lodhran","invoiceRefNo":"3620291786117DI1771168876103","buyerProvince":"Punjab","sellerAddress":"KHAIR PUR ROAD PULL FAREED ABAD KAHROR PAKKA, Lodhran Kahror Pakka","sellerNTNCNIC":"3620291786117","sellerProvince":"Punjab","buyerBusinessName":"Sajjad","sellerBusinessName":"ZIA CORPORATION","buyerRegistrationType":"Unregistered","buyerNTNCNIC":""}	{\n\t\t\t\t\t\t\t\t\t\t\t"invoiceNumber": "3620291786117DIACPWRK747110",\n\t\t\t\t\t\t\t\t\t\t\t"dated": "2026-02-15 22:43:10",\n\t\t\t\t\t\t\t\t\t\t\t"validationResponse": {"statusCode":"00","status":"Valid","error":"","invoiceStatuses":[{"itemSNo":"1","statusCode":"00","status":"Valid","invoiceNo":"3620291786117DIACPWRK747110-1","errorCode":"","error":""}]}\n\n\t\t\t\t\t\t\t\t\t\t\t}	success	2026-02-15 17:44:52	2026-02-15 17:44:54	\N	1469	0	production	\N	1488	407377900899e64fd9d7be2cb482e64a9c25d7c19ee7fc788c41bca148ebc5ab
82	13	{"items":[{"uoM":"KG","rate":"5%","hsCode":"3105.3000","discount":0,"extraTax":0,"quantity":3,"saleType":"3rd Schedule Goods","fedPayable":0,"furtherTax":0,"totalValues":273.22,"productDescription":"Dap","salesTaxApplicable":13.01,"valueSalesExcludingST":260.21,"salesTaxWithheldAtSource":0,"fixedNotifiedValueOrRetailPrice":260.21,"sroScheduleNo":"3rd Schedule goods","sroItemSerialNo":"51"}],"invoiceDate":"2026-02-14","invoiceType":"Sale Invoice","documentTypeId":1,"buyerAddress":"lodhran","invoiceRefNo":"3620291786117DI1771073421293","buyerProvince":"Punjab","sellerAddress":"KHAIR PUR ROAD PULL FAREED ABAD KAHROR PAKKA, Lodhran Kahror Pakka","sellerNTNCNIC":"3620291786117","sellerProvince":"Punjab","buyerBusinessName":"Abrar Ahmad","sellerBusinessName":"ZIA CORPORATION","buyerRegistrationType":"Unregistered","buyerNTNCNIC":""}	{"note":"FBR returned error 500 \\"Something went wrong\\" - invoice may be accepted, needs manual verification","original_response":{"dated":"2026-02-14 18:55:40","validationResponse":{"statusCode":"01","status":"Invalid","errorCode":"500","error":"Some thing went wrong. Please try again later."}}}	pending_verification	2026-02-14 13:56:55	2026-02-14 13:56:57	ambiguous_response	1403	0	production	unknown	1419	\N
83	13	{"items":[{"uoM":"KG","rate":"5%","hsCode":"3105.3000","discount":0,"extraTax":0,"quantity":3,"saleType":"3rd Schedule Goods","fedPayable":0,"furtherTax":0,"totalValues":273.22,"productDescription":"Dap","salesTaxApplicable":13.01,"valueSalesExcludingST":260.21,"salesTaxWithheldAtSource":0,"fixedNotifiedValueOrRetailPrice":260.21,"sroScheduleNo":"3rd Schedule goods","sroItemSerialNo":"51"}],"invoiceDate":"2026-02-14","invoiceType":"Sale Invoice","documentTypeId":1,"buyerAddress":"lodhran","invoiceRefNo":"3620291786117DI1771073421293","buyerProvince":"Punjab","sellerAddress":"KHAIR PUR ROAD PULL FAREED ABAD KAHROR PAKKA, Lodhran Kahror Pakka","sellerNTNCNIC":"3620291786117","sellerProvince":"Punjab","buyerBusinessName":"Abrar Ahmad","sellerBusinessName":"ZIA CORPORATION","buyerRegistrationType":"Unregistered","buyerNTNCNIC":""}	{"note":"FBR returned 200 OK with empty body - status unknown, needs manual verification","http_code":200,"response_headers":{"access-control-allow-origin":"*","access-control-allow-methods":"POST, GET","x-content-type-options":"nosniff","pragma":"no-cache","x-frame-options":"DENY","access-control-allow-headers":"authorization,Access-Control-Allow-Origin,Content-Type,SOAPAction","referrer-policy":"origin","strict-transport-security":"max-age=31536000; includeSubDomains; preload","cache-control":"no-cache, no-store, must-revalidate","content-security-policy":"default-src 'self' *.appdynamics.com *.eum-appdynamics.com *.youtube.com *  'unsafe-inline' 'unsafe-eval'","feature-policy":"microphone 'none'; geolocation 'none'","set-cookie":"cookiesession1=678B28ED3B801D15BD70267C77C79E10;Expires=Sun, 14 Feb 2027 13:57:03 GMT;Path=\\/;HttpOnly","expires":"Thu, 01 Jan 1970 00:00:00 GMT","x-xss-protection":"1; mode=block","content-type":"application\\/json; charset=UTF-8","date":"Sat, 14 Feb 2026 13:56:08 GMT","content-length":"0"},"server_ip":"103.125.60.111","total_time_sec":1.48665}	pending_verification	2026-02-14 13:57:02	2026-02-14 13:57:03	ambiguous_response	1487	0	production	unknown	1500	\N
84	13	{"items":[{"uoM":"KG","rate":"5%","hsCode":"3105.3000","discount":0,"extraTax":0,"quantity":3,"saleType":"3rd Schedule Goods","fedPayable":0,"furtherTax":0,"totalValues":273.22,"productDescription":"Dap","salesTaxApplicable":13.01,"valueSalesExcludingST":260.21,"salesTaxWithheldAtSource":0,"fixedNotifiedValueOrRetailPrice":260.21,"sroScheduleNo":"3rd Schedule goods","sroItemSerialNo":"51"}],"invoiceDate":"2026-02-14","invoiceType":"Sale Invoice","documentTypeId":1,"buyerAddress":"lodhran","invoiceRefNo":"3620291786117DI1771073421293","buyerProvince":"Punjab","sellerAddress":"KHAIR PUR ROAD PULL FAREED ABAD KAHROR PAKKA, Lodhran Kahror Pakka","sellerNTNCNIC":"3620291786117","sellerProvince":"Punjab","buyerBusinessName":"Abrar Ahmad","sellerBusinessName":"ZIA CORPORATION","buyerRegistrationType":"Unregistered","buyerNTNCNIC":""}	{"note":"FBR returned 200 OK with empty body - status unknown, needs manual verification","http_code":200,"response_headers":{"access-control-allow-origin":"*","access-control-allow-methods":"POST, GET","x-content-type-options":"nosniff","pragma":"no-cache","x-frame-options":"DENY","access-control-allow-headers":"authorization,Access-Control-Allow-Origin,Content-Type,SOAPAction","referrer-policy":"origin","strict-transport-security":"max-age=31536000; includeSubDomains; preload","cache-control":"no-cache, no-store, must-revalidate","content-security-policy":"default-src 'self' *.appdynamics.com *.eum-appdynamics.com *.youtube.com *  'unsafe-inline' 'unsafe-eval'","feature-policy":"microphone 'none'; geolocation 'none'","set-cookie":"cookiesession1=678B28EDE8905D0A29250B37366B1FBC;Expires=Sun, 14 Feb 2027 13:57:12 GMT;Path=\\/;HttpOnly","expires":"Thu, 01 Jan 1970 00:00:00 GMT","x-xss-protection":"1; mode=block","content-type":"application\\/json; charset=UTF-8","date":"Sat, 14 Feb 2026 13:55:47 GMT","content-length":"0"},"server_ip":"103.125.60.111","total_time_sec":1.112504}	pending_verification	2026-02-14 13:57:11	2026-02-14 13:57:12	ambiguous_response	1113	0	production	unknown	1124	\N
85	13	{"items":[{"uoM":"KG","rate":"5%","hsCode":"3105.3000","discount":0,"extraTax":0,"quantity":3,"saleType":"3rd Schedule Goods","fedPayable":0,"furtherTax":0,"totalValues":273.22,"productDescription":"Dap","salesTaxApplicable":13.01,"valueSalesExcludingST":260.21,"salesTaxWithheldAtSource":0,"fixedNotifiedValueOrRetailPrice":260.21,"sroScheduleNo":"3rd Schedule goods","sroItemSerialNo":"51"}],"invoiceDate":"2026-02-14","invoiceType":"Sale Invoice","documentTypeId":1,"buyerAddress":"lodhran","invoiceRefNo":"3620291786117DI1771077549919","buyerProvince":"Punjab","sellerAddress":"KHAIR PUR ROAD PULL FAREED ABAD KAHROR PAKKA, Lodhran Kahror Pakka","sellerNTNCNIC":"3620291786117","sellerProvince":"Punjab","buyerBusinessName":"Abrar Ahmad","sellerBusinessName":"ZIA CORPORATION","buyerRegistrationType":"Unregistered","buyerNTNCNIC":""}	{"note":"FBR returned 200 OK with empty body - status unknown, needs manual verification","http_code":200,"response_headers":{"access-control-allow-origin":"*","access-control-allow-methods":"POST, GET","x-content-type-options":"nosniff","pragma":"no-cache","x-frame-options":"DENY","access-control-allow-headers":"authorization,Access-Control-Allow-Origin,Content-Type,SOAPAction","referrer-policy":"origin","strict-transport-security":"max-age=31536000; includeSubDomains; preload","cache-control":"no-cache, no-store, must-revalidate","content-security-policy":"default-src 'self' *.appdynamics.com *.eum-appdynamics.com *.youtube.com *  'unsafe-inline' 'unsafe-eval'","feature-policy":"microphone 'none'; geolocation 'none'","set-cookie":"cookiesession1=678B28ED1284BE87F6B2D7DA23A4A465;Expires=Sun, 14 Feb 2027 13:59:43 GMT;Path=\\/;HttpOnly","expires":"Thu, 01 Jan 1970 00:00:00 GMT","x-xss-protection":"1; mode=block","content-type":"application\\/json; charset=UTF-8","date":"Sat, 14 Feb 2026 13:59:17 GMT","content-length":"0"},"server_ip":"103.125.60.111","total_time_sec":1.454599}	pending_verification	2026-02-14 13:59:41	2026-02-14 13:59:43	ambiguous_response	1455	0	production	unknown	1473	\N
86	13	{"items":[{"uoM":"KG","rate":"5%","hsCode":"3105.3000","discount":0,"extraTax":0,"quantity":3,"saleType":"3rd Schedule Goods","fedPayable":0,"furtherTax":0,"totalValues":273.22,"productDescription":"Dap","salesTaxApplicable":13.01,"valueSalesExcludingST":260.21,"salesTaxWithheldAtSource":0,"fixedNotifiedValueOrRetailPrice":260.21,"sroScheduleNo":"3rd Schedule goods","sroItemSerialNo":"51"}],"invoiceDate":"2026-02-14","invoiceType":"Sale Invoice","documentTypeId":1,"buyerAddress":"lodhran","invoiceRefNo":"3620291786117DI1771077549919","buyerProvince":"Punjab","sellerAddress":"KHAIR PUR ROAD PULL FAREED ABAD KAHROR PAKKA, Lodhran Kahror Pakka","sellerNTNCNIC":"3620291786117","sellerProvince":"Punjab","buyerBusinessName":"Abrar Ahmad","sellerBusinessName":"ZIA CORPORATION","buyerRegistrationType":"Unregistered","buyerNTNCNIC":""}	{\n\t\t\t\t\t\t\t\t\t\t\t"invoiceNumber": "3620291786117DIACOSGL329267",\n\t\t\t\t\t\t\t\t\t\t\t"dated": "2026-02-14 18:58:37",\n\t\t\t\t\t\t\t\t\t\t\t"validationResponse": {"statusCode":"00","status":"Valid","error":"","invoiceStatuses":[{"itemSNo":"1","statusCode":"00","status":"Valid","invoiceNo":"3620291786117DIACOSGL329267-1","errorCode":"","error":""}]}\n\n\t\t\t\t\t\t\t\t\t\t\t}	success	2026-02-14 13:59:53	2026-02-14 13:59:54	\N	1126	0	production	\N	1140	\N
121	18	{"items":[{"uoM":"KG","rate":"5%","hsCode":"3105.3000","discount":0,"extraTax":0,"quantity":1,"saleType":"3rd Schedule Goods","fedPayable":0,"furtherTax":0,"totalValues":273.22,"productDescription":"Dap","salesTaxApplicable":13.01,"valueSalesExcludingST":260.21,"salesTaxWithheldAtSource":0,"fixedNotifiedValueOrRetailPrice":260.21,"sroScheduleNo":"3rd Schedule goods","sroItemSerialNo":"51"}],"invoiceDate":"2026-02-15","invoiceType":"Sale Invoice","documentTypeId":1,"buyerAddress":"kahror pakka","invoiceRefNo":"3620291786117DI1771166704525","buyerProvince":"Punjab","sellerAddress":"KHAIR PUR ROAD PULL FAREED ABAD KAHROR PAKKA, Lodhran Kahror Pakka","sellerNTNCNIC":"3620291786117","sellerProvince":"Punjab","buyerBusinessName":"NISAR","sellerBusinessName":"ZIA CORPORATION","buyerRegistrationType":"Unregistered","buyerNTNCNIC":""}	{\n\t\t\t\t\t\t\t\t\t\t\t"invoiceNumber": "3620291786117DIACPTQT003255",\n\t\t\t\t\t\t\t\t\t\t\t"dated": "2026-02-15 19:42:45",\n\t\t\t\t\t\t\t\t\t\t\t"validationResponse": {"statusCode":"00","status":"Valid","error":"","invoiceStatuses":[{"itemSNo":"1","statusCode":"00","status":"Valid","invoiceNo":"3620291786117DIACPTQT003255-1","errorCode":"","error":""}]}\n\n\t\t\t\t\t\t\t\t\t\t\t}	success	2026-02-15 14:45:04	2026-02-15 14:45:05	\N	1404	0	production	\N	1418	\N
123	19	{"items":[{"uoM":"KG","rate":"5%","hsCode":"3105.3000","discount":0,"extraTax":0,"quantity":3,"saleType":"3rd Schedule Goods","fedPayable":0,"furtherTax":0,"totalValues":819.66,"productDescription":"Dap","salesTaxApplicable":39.03,"valueSalesExcludingST":780.63,"salesTaxWithheldAtSource":0,"fixedNotifiedValueOrRetailPrice":260.21,"sroScheduleNo":"3rd Schedule goods","sroItemSerialNo":"51"}],"invoiceDate":"2026-02-15","invoiceType":"Sale Invoice","documentTypeId":1,"buyerAddress":"Lodhran","invoiceRefNo":"3620291786117DI1771166839532","buyerProvince":"Punjab","sellerAddress":"KHAIR PUR ROAD PULL FAREED ABAD KAHROR PAKKA, Lodhran Kahror Pakka","sellerNTNCNIC":"3620291786117","sellerProvince":"Punjab","buyerBusinessName":"Sajjad","sellerBusinessName":"ZIA CORPORATION","buyerRegistrationType":"Unregistered","buyerNTNCNIC":""}	{\n\n\t\t\t\t\t\t\t\t\t\t\t"dated": "2026-02-15 19:47:38" ,\n\t\t\t\t\t\t\t\t\t\t\t"validationResponse": {"statusCode":"01","status":"Invalid","error":"","invoiceStatuses":[{"itemSNo":"1","statusCode":"01","status":"Invalid","invoiceNo":null,"errorCode":"0102","error":"Provided sales tax amount does not match the calculated sales tax amount in case of 3rd schedule goods. Please ensure that the Fixed/Notified Value or Retail Price is used to calculated the Sales Tax Amount for the provided rate."}]}\n\n\t\t\t\t\t\t\t\t\t\t\t}	failed	2026-02-15 14:49:20	2026-02-15 14:49:21	validation_error	1193	0	production	validation	1204	\N
124	19	{"items":[{"uoM":"KG","rate":5,"hsCode":"3105.3000","discount":0,"extraTax":0,"quantity":3,"saleType":"3rd Schedule Goods","fedPayable":0,"furtherTax":0,"totalValues":819.66,"productDescription":"Dap","salesTaxApplicable":39.03,"valueSalesExcludingST":780.63,"salesTaxWithheldAtSource":0,"fixedNotifiedValueOrRetailPrice":260.21,"sroScheduleNo":"3rd Schedule goods","sroItemSerialNo":"51"}],"invoiceDate":"2026-02-15","invoiceType":"Sale Invoice","documentTypeId":1,"buyerAddress":"Lodhran","invoiceRefNo":"3620291786117DI1771166839532","buyerProvince":"Punjab","sellerAddress":"KHAIR PUR ROAD PULL FAREED ABAD KAHROR PAKKA, Lodhran Kahror Pakka","sellerNTNCNIC":"3620291786117","sellerProvince":"Punjab","buyerBusinessName":"Sajjad","sellerBusinessName":"ZIA CORPORATION","buyerRegistrationType":"Unregistered","buyerNTNCNIC":""}	{"note":"FBR returned error 500 \\"Something went wrong\\" - invoice may be accepted, needs manual verification","original_response":{"dated":"2026-02-15 19:52:57","validationResponse":{"statusCode":"01","status":"Invalid","errorCode":"500","error":"Some thing went wrong. Please try again later."}}}	pending_verification	2026-02-15 14:54:13	2026-02-15 14:54:15	ambiguous_response	1273	0	\N	\N	\N	\N
126	19	{"items":[{"uoM":"KG","rate":5,"hsCode":"3105.3000","discount":0,"extraTax":0,"quantity":3,"saleType":"3rd Schedule Goods","fedPayable":0,"furtherTax":0,"totalValues":819.66,"productDescription":"Dap","salesTaxApplicable":39.03,"valueSalesExcludingST":780.63,"salesTaxWithheldAtSource":0,"fixedNotifiedValueOrRetailPrice":260.21,"sroScheduleNo":"3rd Schedule goods","sroItemSerialNo":"51"}],"invoiceDate":"2026-02-15","invoiceType":"Sale Invoice","documentTypeId":1,"buyerAddress":"Lodhran","invoiceRefNo":"3620291786117DI1771166839532","buyerProvince":"Punjab","sellerAddress":"KHAIR PUR ROAD PULL FAREED ABAD KAHROR PAKKA, Lodhran Kahror Pakka","sellerNTNCNIC":"3620291786117","sellerProvince":"Punjab","buyerBusinessName":"Sajjad","sellerBusinessName":"ZIA CORPORATION","buyerRegistrationType":"Unregistered","buyerNTNCNIC":""}	{"note":"FBR returned error 500 \\"Something went wrong\\" - invoice may be accepted, needs manual verification","original_response":{"dated":"2026-02-15 19:54:43","validationResponse":{"statusCode":"01","status":"Invalid","errorCode":"500","error":"Some thing went wrong. Please try again later."}}}	pending_verification	2026-02-15 14:54:45	2026-02-15 14:54:46	ambiguous_response	1272	0	\N	\N	\N	\N
139	26	{"items":[{"uoM":"KG","rate":"5%","hsCode":"3105.3000","discount":0,"extraTax":0,"quantity":10,"saleType":"3rd Schedule Goods","fedPayable":0,"furtherTax":0,"totalValues":2732.21,"productDescription":"Dap","salesTaxApplicable":130.11,"valueSalesExcludingST":2602.1,"salesTaxWithheldAtSource":0,"fixedNotifiedValueOrRetailPrice":2602.1,"sroScheduleNo":"3rd Schedule goods","sroItemSerialNo":"51"}],"invoiceDate":"2026-02-16","invoiceType":"Sale Invoice","documentTypeId":1,"buyerAddress":"Kahror pakka","invoiceRefNo":"3620291786117DI3620291786117DI1771221712284","buyerProvince":"Punjab","sellerAddress":"KHAIR PUR ROAD PULL FAREED ABAD KAHROR PAKKA, Lodhran Kahror Pakka","sellerNTNCNIC":"3620291786117","sellerProvince":"Punjab","buyerBusinessName":"walk in cutomer","sellerBusinessName":"ZIA CORPORATION","buyerRegistrationType":"Unregistered","buyerNTNCNIC":""}	{\n\t\t\t\t\t\t\t\t\t\t\t"invoiceNumber": "3620291786117DIACQLAN954426",\n\t\t\t\t\t\t\t\t\t\t\t"dated": "2026-02-16 11:00:39",\n\t\t\t\t\t\t\t\t\t\t\t"validationResponse": {"statusCode":"00","status":"Valid","error":"","invoiceStatuses":[{"itemSNo":"1","statusCode":"00","status":"Valid","invoiceNo":"3620291786117DIACQLAN954426-1","errorCode":"","error":""}]}\n\n\t\t\t\t\t\t\t\t\t\t\t}	success	2026-02-16 06:01:56	2026-02-16 06:01:58	\N	1392	0	production	\N	1420	\N
140	27	{"items":[{"uoM":"KG","rate":"5%","hsCode":"3105.3000","discount":0,"extraTax":0,"quantity":5,"saleType":"3rd Schedule Goods","fedPayable":0,"furtherTax":0,"totalValues":1366.1,"productDescription":"Dap","salesTaxApplicable":65.05,"valueSalesExcludingST":1301.05,"salesTaxWithheldAtSource":0,"fixedNotifiedValueOrRetailPrice":1301.05,"sroScheduleNo":"3rd Schedule goods","sroItemSerialNo":"51"}],"invoiceDate":"2026-02-16","invoiceType":"Sale Invoice","documentTypeId":1,"buyerAddress":"THADDA THAHEEM LODHRAN","invoiceRefNo":"3620291786117DI3620291786117DI1771246493957","buyerProvince":"Punjab","sellerAddress":"KHAIR PUR ROAD PULL FAREED ABAD KAHROR PAKKA, Lodhran Kahror Pakka","sellerNTNCNIC":"3620291786117","sellerProvince":"Punjab","buyerBusinessName":"MALIK FAWAD","sellerBusinessName":"ZIA CORPORATION","buyerRegistrationType":"Unregistered","buyerNTNCNIC":""}	{\n\t\t\t\t\t\t\t\t\t\t\t"invoiceNumber": "3620291786117DIACQRBD942496",\n\t\t\t\t\t\t\t\t\t\t\t"dated": "2026-02-16 17:53:29",\n\t\t\t\t\t\t\t\t\t\t\t"validationResponse": {"statusCode":"00","status":"Valid","error":"","invoiceStatuses":[{"itemSNo":"1","statusCode":"00","status":"Valid","invoiceNo":"3620291786117DIACQRBD942496-1","errorCode":"","error":""}]}\n\n\t\t\t\t\t\t\t\t\t\t\t}	success	2026-02-16 12:55:12	2026-02-16 12:55:14	\N	1513	0	production	\N	1539	\N
141	28	{"items":[{"uoM":"KG","rate":"5%","hsCode":"3105.3000","discount":0,"extraTax":0,"quantity":6,"saleType":"3rd Schedule Goods","fedPayable":0,"furtherTax":0,"totalValues":1639.32,"productDescription":"Dap","salesTaxApplicable":78.06,"valueSalesExcludingST":1561.26,"salesTaxWithheldAtSource":0,"fixedNotifiedValueOrRetailPrice":1561.26,"sroScheduleNo":"3rd Schedule goods","sroItemSerialNo":"51"}],"invoiceDate":"2026-02-17","invoiceType":"Sale Invoice","documentTypeId":1,"buyerAddress":"Kahror pakka","invoiceRefNo":"3620291786117DI3620291786117DI1771318614634","buyerProvince":"Punjab","sellerAddress":"KHAIR PUR ROAD PULL FAREED ABAD KAHROR PAKKA, Lodhran Kahror Pakka","sellerNTNCNIC":"3620291786117","sellerProvince":"Punjab","buyerBusinessName":"walk in cutomer","sellerBusinessName":"ZIA CORPORATION","buyerRegistrationType":"Unregistered","buyerNTNCNIC":""}	{\n\t\t\t\t\t\t\t\t\t\t\t"invoiceNumber": "3620291786117DIACRNFN740406",\n\t\t\t\t\t\t\t\t\t\t\t"dated": "2026-02-17 13:57:13",\n\t\t\t\t\t\t\t\t\t\t\t"validationResponse": {"statusCode":"00","status":"Valid","error":"","invoiceStatuses":[{"itemSNo":"1","statusCode":"00","status":"Valid","invoiceNo":"3620291786117DIACRNFN740406-1","errorCode":"","error":""}]}\n\n\t\t\t\t\t\t\t\t\t\t\t}	success	2026-02-17 08:57:13	2026-02-17 08:57:15	\N	1433	0	production	\N	1458	\N
143	30	{"items":[{"uoM":"KG","rate":"5%","hsCode":"3105.3000","discount":0,"extraTax":0,"quantity":1,"saleType":"3rd Schedule Goods","fedPayable":0,"furtherTax":10.41,"totalValues":283.63,"productDescription":"Dap","salesTaxApplicable":13.01,"valueSalesExcludingST":260.21,"salesTaxWithheldAtSource":0,"fixedNotifiedValueOrRetailPrice":260.21,"sroScheduleNo":"3rd Schedule goods","sroItemSerialNo":"51"}],"invoiceDate":"2026-02-17","invoiceType":"Sale Invoice","documentTypeId":1,"buyerAddress":"Kahror pakka","invoiceRefNo":"3620291786117DI3620291786117DI1771321000267","buyerProvince":"Punjab","sellerAddress":"KHAIR PUR ROAD PULL FAREED ABAD KAHROR PAKKA, Lodhran Kahror Pakka","sellerNTNCNIC":"3620291786117","sellerProvince":"Punjab","buyerBusinessName":"walk in cutomer","sellerBusinessName":"ZIA CORPORATION","buyerRegistrationType":"Unregistered","buyerNTNCNIC":""}	{\n\t\t\t\t\t\t\t\t\t\t\t"invoiceNumber": "3620291786117DIACROKV587270",\n\t\t\t\t\t\t\t\t\t\t\t"dated": "2026-02-17 14:36:46",\n\t\t\t\t\t\t\t\t\t\t\t"validationResponse": {"statusCode":"00","status":"Valid","error":"","invoiceStatuses":[{"itemSNo":"1","statusCode":"00","status":"Valid","invoiceNo":"3620291786117DIACROKV587270-1","errorCode":"","error":""}]}\n\n\t\t\t\t\t\t\t\t\t\t\t}	success	2026-02-17 09:36:46	2026-02-17 09:36:48	\N	1403	0	production	\N	1420	\N
120	18	{"items":[{"uoM":"KG","rate":"5%","hsCode":"3105.3000","discount":0,"extraTax":0,"quantity":1,"saleType":"3rd Schedule Goods","fedPayable":0,"furtherTax":0,"totalValues":273.22,"productDescription":"Dap","salesTaxApplicable":13.01,"valueSalesExcludingST":260.21,"salesTaxWithheldAtSource":0,"fixedNotifiedValueOrRetailPrice":260.21,"sroScheduleNo":"3rd Schedule goods","sroItemSerialNo":"51"}],"invoiceDate":"2026-02-15","invoiceType":"Sale Invoice","documentTypeId":1,"buyerAddress":"kahror pakka","invoiceRefNo":"3620291786117DI1771166107040","buyerProvince":"Punjab","sellerAddress":"KHAIR PUR ROAD PULL FAREED ABAD KAHROR PAKKA, Lodhran Kahror Pakka","sellerNTNCNIC":"3620291786117","sellerProvince":"Punjab","buyerBusinessName":"NISAR","sellerBusinessName":"ZIA CORPORATION","buyerRegistrationType":"Unregistered","buyerNTNCNIC":""}	{\n\t\t\t\t\t\t\t\t\t\t\t"invoiceNumber": "3620291786117DIACPTHZ406133",\n\t\t\t\t\t\t\t\t\t\t\t"dated": "2026-02-15 19:33:25",\n\t\t\t\t\t\t\t\t\t\t\t"validationResponse": {"statusCode":"00","status":"Valid","error":"","invoiceStatuses":[{"itemSNo":"1","statusCode":"00","status":"Valid","invoiceNo":"3620291786117DIACPTHZ406133-1","errorCode":"","error":""}]}\n\n\t\t\t\t\t\t\t\t\t\t\t}	success	2026-02-15 14:35:07	2026-02-15 14:35:08	\N	1421	0	\N	\N	\N	\N
122	19	{"items":[{"uoM":"KG","rate":"5%","hsCode":"3105.3000","discount":0,"extraTax":0,"quantity":3,"saleType":"3rd Schedule Goods","fedPayable":0,"furtherTax":0,"totalValues":819.66,"productDescription":"Dap","salesTaxApplicable":39.03,"valueSalesExcludingST":780.63,"salesTaxWithheldAtSource":0,"fixedNotifiedValueOrRetailPrice":260.21,"sroScheduleNo":"3rd Schedule goods","sroItemSerialNo":"51"}],"invoiceDate":"2026-02-15","invoiceType":"Sale Invoice","documentTypeId":1,"buyerAddress":"Lodhran","invoiceRefNo":"3620291786117DI1771166839532","buyerProvince":"Punjab","sellerAddress":"KHAIR PUR ROAD PULL FAREED ABAD KAHROR PAKKA, Lodhran Kahror Pakka","sellerNTNCNIC":"3620291786117","sellerProvince":"Punjab","buyerBusinessName":"Sajjad","sellerBusinessName":"ZIA CORPORATION","buyerRegistrationType":"Unregistered","buyerNTNCNIC":""}	{\n\n\t\t\t\t\t\t\t\t\t\t\t"dated": "2026-02-15 19:45:06" ,\n\t\t\t\t\t\t\t\t\t\t\t"validationResponse": {"statusCode":"01","status":"Invalid","error":"","invoiceStatuses":[{"itemSNo":"1","statusCode":"01","status":"Invalid","invoiceNo":null,"errorCode":"0102","error":"Provided sales tax amount does not match the calculated sales tax amount in case of 3rd schedule goods. Please ensure that the Fixed/Notified Value or Retail Price is used to calculated the Sales Tax Amount for the provided rate."}]}\n\n\t\t\t\t\t\t\t\t\t\t\t}	failed	2026-02-15 14:47:26	2026-02-15 14:47:27	validation_error	1396	0	production	validation	1412	\N
125	19	{"items":[{"uoM":"KG","rate":5,"hsCode":"3105.3000","discount":0,"extraTax":0,"quantity":3,"saleType":"3rd Schedule Goods","fedPayable":0,"furtherTax":0,"totalValues":819.66,"productDescription":"Dap","salesTaxApplicable":39.03,"valueSalesExcludingST":780.63,"salesTaxWithheldAtSource":0,"fixedNotifiedValueOrRetailPrice":260.21,"sroScheduleNo":"3rd Schedule goods","sroItemSerialNo":"51"}],"invoiceDate":"2026-02-15","invoiceType":"Sale Invoice","documentTypeId":1,"buyerAddress":"Lodhran","invoiceRefNo":"3620291786117DI1771166839532","buyerProvince":"Punjab","sellerAddress":"KHAIR PUR ROAD PULL FAREED ABAD KAHROR PAKKA, Lodhran Kahror Pakka","sellerNTNCNIC":"3620291786117","sellerProvince":"Punjab","buyerBusinessName":"Sajjad","sellerBusinessName":"ZIA CORPORATION","buyerRegistrationType":"Unregistered","buyerNTNCNIC":""}	{"note":"FBR returned error 500 \\"Something went wrong\\" - invoice may be accepted, needs manual verification","original_response":{"dated":"2026-02-15 19:53:11","validationResponse":{"statusCode":"01","status":"Invalid","errorCode":"500","error":"Some thing went wrong. Please try again later."}}}	pending_verification	2026-02-15 14:54:27	2026-02-15 14:54:28	ambiguous_response	1073	0	\N	\N	\N	\N
127	19	{"items":[{"uoM":"KG","rate":5,"hsCode":"3105.3000","discount":0,"extraTax":0,"quantity":4,"saleType":"3rd Schedule Goods","fedPayable":0,"furtherTax":0,"totalValues":1092.88,"productDescription":"Dap","salesTaxApplicable":52.04,"valueSalesExcludingST":1040.84,"salesTaxWithheldAtSource":0,"fixedNotifiedValueOrRetailPrice":260.21,"sroScheduleNo":"3rd Schedule goods","sroItemSerialNo":"51"}],"invoiceDate":"2026-02-15","invoiceType":"Sale Invoice","documentTypeId":1,"buyerAddress":"Lodhran","invoiceRefNo":"3620291786117DI1771166839532","buyerProvince":"Punjab","sellerAddress":"KHAIR PUR ROAD PULL FAREED ABAD KAHROR PAKKA, Lodhran Kahror Pakka","sellerNTNCNIC":"3620291786117","sellerProvince":"Punjab","buyerBusinessName":"Sajjad","sellerBusinessName":"ZIA CORPORATION","buyerRegistrationType":"Unregistered","buyerNTNCNIC":""}	{"note":"FBR returned error 500 \\"Something went wrong\\" - invoice may be accepted, needs manual verification","original_response":{"dated":"2026-02-15 19:55:35","validationResponse":{"statusCode":"01","status":"Invalid","errorCode":"500","error":"Some thing went wrong. Please try again later."}}}	pending_verification	2026-02-15 14:56:52	2026-02-15 14:56:53	ambiguous_response	1306	0	\N	\N	\N	\N
128	19	{"items":[{"uoM":"KG","rate":5,"hsCode":"3105.3000","discount":0,"extraTax":0,"quantity":4,"saleType":"3rd Schedule Goods","fedPayable":0,"furtherTax":0,"totalValues":1092.88,"productDescription":"Dap","salesTaxApplicable":52.04,"valueSalesExcludingST":1040.84,"salesTaxWithheldAtSource":0,"fixedNotifiedValueOrRetailPrice":260.21,"sroScheduleNo":"3rd Schedule goods","sroItemSerialNo":"51"}],"invoiceDate":"2026-02-15","invoiceType":"Sale Invoice","documentTypeId":1,"buyerAddress":"Lodhran","invoiceRefNo":"3620291786117DI1771166839532","buyerProvince":"Punjab","sellerAddress":"KHAIR PUR ROAD PULL FAREED ABAD KAHROR PAKKA, Lodhran Kahror Pakka","sellerNTNCNIC":"3620291786117","sellerProvince":"Punjab","buyerBusinessName":"Sajjad","sellerBusinessName":"ZIA CORPORATION","buyerRegistrationType":"Unregistered","buyerNTNCNIC":""}	{"note":"FBR returned error 500 \\"Something went wrong\\" - invoice may be accepted, needs manual verification","original_response":{"dated":"2026-02-15 19:55:11","validationResponse":{"statusCode":"01","status":"Invalid","errorCode":"500","error":"Some thing went wrong. Please try again later."}}}	pending_verification	2026-02-15 14:57:30	2026-02-15 14:57:32	ambiguous_response	1166	0	\N	\N	\N	\N
129	19	{"items":[{"uoM":"KG","rate":5,"hsCode":"3105.3000","discount":0,"extraTax":0,"quantity":4,"saleType":"3rd Schedule Goods","fedPayable":0,"furtherTax":0,"totalValues":1092.88,"productDescription":"Dap","salesTaxApplicable":52.04,"valueSalesExcludingST":1040.84,"salesTaxWithheldAtSource":0,"fixedNotifiedValueOrRetailPrice":260.21,"sroScheduleNo":"3rd Schedule goods","sroItemSerialNo":"51"}],"invoiceDate":"2026-02-15","invoiceType":"Sale Invoice","documentTypeId":1,"buyerAddress":"Lodhran","invoiceRefNo":"3620291786117DI1771166839532","buyerProvince":"Punjab","sellerAddress":"KHAIR PUR ROAD PULL FAREED ABAD KAHROR PAKKA, Lodhran Kahror Pakka","sellerNTNCNIC":"3620291786117","sellerProvince":"Punjab","buyerBusinessName":"Sajjad","sellerBusinessName":"ZIA CORPORATION","buyerRegistrationType":"Unregistered","buyerNTNCNIC":""}	{"note":"FBR returned error 500 \\"Something went wrong\\" - invoice may be accepted, needs manual verification","original_response":{"dated":"2026-02-15 20:14:45","validationResponse":{"statusCode":"01","status":"Invalid","errorCode":"500","error":"Some thing went wrong. Please try again later."}}}	pending_verification	2026-02-15 15:16:26	2026-02-15 15:16:28	ambiguous_response	1382	0	\N	\N	\N	\N
130	21	{"items":[{"uoM":"KG","rate":5,"hsCode":"3105.3000","discount":0,"extraTax":0,"quantity":1,"saleType":"3rd Schedule Goods","fedPayable":0,"furtherTax":0,"totalValues":1092.88,"productDescription":"Dap","salesTaxApplicable":52.04,"valueSalesExcludingST":1040.84,"salesTaxWithheldAtSource":0,"fixedNotifiedValueOrRetailPrice":1040.84,"sroScheduleNo":"3rd Schedule goods","sroItemSerialNo":"51"}],"invoiceDate":"2026-02-15","invoiceType":"Sale Invoice","documentTypeId":1,"buyerAddress":"Lodhran","invoiceRefNo":"3620291786117DI1771168876103","buyerProvince":"Punjab","sellerAddress":"KHAIR PUR ROAD PULL FAREED ABAD KAHROR PAKKA, Lodhran Kahror Pakka","sellerNTNCNIC":"3620291786117","sellerProvince":"Punjab","buyerBusinessName":"Sajjad","sellerBusinessName":"ZIA CORPORATION","buyerRegistrationType":"Unregistered","buyerNTNCNIC":""}	{"note":"FBR returned error 500 \\"Something went wrong\\" - invoice may be accepted, needs manual verification","original_response":{"dated":"2026-02-15 20:19:59","validationResponse":{"statusCode":"01","status":"Invalid","errorCode":"500","error":"Some thing went wrong. Please try again later."}}}	pending_verification	2026-02-15 15:21:16	2026-02-15 15:21:17	ambiguous_response	1166	0	\N	\N	\N	\N
131	19	{"items":[{"uoM":"KG","rate":"5%","hsCode":"3105.3000","discount":0,"extraTax":0,"quantity":4,"saleType":"3rd Schedule Goods","fedPayable":0,"furtherTax":0,"totalValues":1092.88,"productDescription":"Dap","salesTaxApplicable":52.04,"valueSalesExcludingST":1040.84,"salesTaxWithheldAtSource":0,"fixedNotifiedValueOrRetailPrice":260.21,"sroScheduleNo":"3rd Schedule goods","sroItemSerialNo":"51"}],"invoiceDate":"2026-02-15","invoiceType":"Sale Invoice","documentTypeId":1,"buyerAddress":"Lodhran","invoiceRefNo":"3620291786117DI1771169303713","buyerProvince":"Punjab","sellerAddress":"KHAIR PUR ROAD PULL FAREED ABAD KAHROR PAKKA, Lodhran Kahror Pakka","sellerNTNCNIC":"3620291786117","sellerProvince":"Punjab","buyerBusinessName":"Sajjad","sellerBusinessName":"ZIA CORPORATION","buyerRegistrationType":"Unregistered","buyerNTNCNIC":""}	{\n\n\t\t\t\t\t\t\t\t\t\t\t"dated": "2026-02-15 20:28:22" ,\n\t\t\t\t\t\t\t\t\t\t\t"validationResponse": {"statusCode":"01","status":"Invalid","error":"","invoiceStatuses":[{"itemSNo":"1","statusCode":"01","status":"Invalid","invoiceNo":null,"errorCode":"0102","error":"Provided sales tax amount does not match the calculated sales tax amount in case of 3rd schedule goods. Please ensure that the Fixed/Notified Value or Retail Price is used to calculated the Sales Tax Amount for the provided rate."}]}\n\n\t\t\t\t\t\t\t\t\t\t\t}	failed	2026-02-15 15:28:23	2026-02-15 15:28:25	validation_error	1482	0	\N	\N	\N	\N
\.


--
-- Data for Name: franchises; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.franchises (id, name, email, phone, commission_rate, status, password, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: global_hs_master; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.global_hs_master (id, hs_code, description, pct_code, schedule_type, tax_rate, default_uom, sro_required, sro_number, sro_item_serial_no, mrp_required, sector_tag, risk_weight, mapping_status, created_by, updated_by, created_at, updated_at, st_withheld_applicable, petroleum_levy_applicable) FROM stdin;
5	87032100	\N	8703.2100	3rd_schedule	17.00	\N	t	\N	\N	t	\N	0.00	Mapped	\N	\N	2026-02-11 18:23:06	2026-02-11 18:23:06	f	f
6	02023000	\N	0202.3000	exempt	0.00	\N	t	\N	\N	f	\N	0.00	Mapped	\N	\N	2026-02-11 18:23:06	2026-02-11 18:23:06	f	f
7	04011000	\N	0401.1000	zero_rated	0.00	\N	f	\N	\N	f	\N	0.00	Mapped	\N	\N	2026-02-11 18:23:06	2026-02-11 18:23:06	f	f
8	10063090	\N	1006.3090	zero_rated	0.00	\N	f	\N	\N	f	\N	0.00	Mapped	\N	\N	2026-02-11 18:23:06	2026-02-11 18:23:06	f	f
9	11010010	\N	1101.0010	zero_rated	0.00	\N	f	\N	\N	f	\N	0.00	Mapped	\N	\N	2026-02-11 18:23:06	2026-02-11 18:23:06	f	f
10	30049099	\N	3004.9099	exempt	0.00	\N	t	\N	\N	f	\N	0.00	Mapped	\N	\N	2026-02-11 18:23:06	2026-02-11 18:23:06	f	f
11	48191000	\N	4819.1000	reduced	10.00	\N	t	\N	\N	f	\N	0.00	Mapped	\N	\N	2026-02-11 18:23:06	2026-02-11 18:23:06	f	f
12	61091000	\N	6109.1000	standard	18.00	\N	f	\N	\N	f	\N	0.00	Mapped	\N	\N	2026-02-11 18:23:06	2026-02-11 18:23:06	f	f
13	62034200	\N	6203.4200	standard	18.00	\N	f	\N	\N	f	\N	0.00	Mapped	\N	\N	2026-02-11 18:23:06	2026-02-11 18:23:06	f	f
14	85171100	\N	8517.1100	3rd_schedule	17.00	\N	t	\N	\N	t	\N	0.00	Mapped	\N	\N	2026-02-11 18:23:06	2026-02-11 18:23:06	f	f
1	15179090	Cooking Oil 1L	1517.9090	standard	18.00	Litre	f	\N	\N	f	\N	0.00	Mapped	\N	\N	2026-02-11 18:23:05	2026-02-11 18:23:06	f	f
3	31021000	Fertilizer	3102.1000	exempt	0.00	Kg	t	\N	\N	f	\N	0.00	Mapped	\N	\N	2026-02-11 18:23:06	2026-02-11 18:23:06	f	f
4	84713010	\N	8471.3010	standard	18.00	Numbers, pieces, units	f	\N	\N	f	\N	0.00	Mapped	\N	\N	2026-02-11 18:23:06	2026-02-11 18:23:06	f	f
16	99887766	Administrative Service Fee	\N	standard	18.00	Numbers, pieces, units	f	\N	\N	f	\N	0.00	Partial	\N	\N	2026-02-11 18:23:06	2026-02-11 18:23:06	f	f
2	25232900	Cement Bag	2523.2900	standard	18.00	Bag	f	\N	\N	f	\N	0.00	Mapped	\N	\N	2026-02-11 18:23:06	2026-02-11 18:23:06	t	f
15	27101990	\N	2710.1990	reduced	10.00	\N	t	\N	\N	f	\N	0.00	Mapped	\N	\N	2026-02-11 18:23:06	2026-02-11 18:23:06	f	t
\.


--
-- Data for Name: hs_code_mappings; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.hs_code_mappings (id, hs_code, label, sale_type, tax_rate, sro_applicable, sro_number, serial_number_applicable, serial_number_value, mrp_required, pct_code, default_uom, buyer_type, notes, priority, is_active, created_by, updated_by, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: hs_intelligence_logs; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.hs_intelligence_logs (id, hs_code, suggested_schedule_type, suggested_tax_rate, suggested_sro_required, suggested_serial_required, suggested_mrp_required, confidence_score, weight_breakdown, based_on_records_count, rejection_factor, industry_factor, created_at) FROM stdin;
1	99990001	standard	18.00	f	f	t	16	"{\\"tax_frequency\\":{\\"weight\\":10,\\"records\\":3},\\"schedule_frequency\\":{\\"weight\\":15,\\"records\\":3}}"	3	0	0	2026-02-12 04:48:13
\.


--
-- Data for Name: hs_mapping_audit_logs; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.hs_mapping_audit_logs (id, hs_code_mapping_id, action, field_name, old_value, new_value, changed_by, snapshot, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: hs_mapping_responses; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.hs_mapping_responses (id, hs_code_mapping_id, company_id, user_id, invoice_id, hs_code, action, custom_values, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: hs_master_global; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.hs_master_global (id, hs_code, description, schedule_type, default_tax_rate, sro_required, default_sro_number, serial_required, default_serial_no, mrp_required, st_withheld_applicable, petroleum_levy_applicable, default_uom, confidence_score, last_source, is_active, created_at, updated_at) FROM stdin;
39	15079000	Refined soybean oil	standard	18.00	f	\N	f	\N	t	f	f	LTR	90	seed	t	2026-02-12 05:04:04	2026-02-12 05:04:04
41	15119000	Refined palm oil	standard	18.00	f	\N	f	\N	t	f	f	LTR	90	seed	t	2026-02-12 05:04:04	2026-02-12 05:04:04
47	19021100	Uncooked pasta (containing eggs)	standard	18.00	f	\N	f	\N	t	f	f	KGS	90	seed	t	2026-02-12 05:04:04	2026-02-12 05:04:04
48	19053100	Sweet biscuits	standard	18.00	f	\N	f	\N	t	f	f	KGS	90	seed	t	2026-02-12 05:04:04	2026-02-12 05:04:04
49	19059000	Bakery products, cakes, pastries	standard	18.00	f	\N	f	\N	t	f	f	KGS	90	seed	t	2026-02-12 05:04:04	2026-02-12 05:04:04
50	20011000	Cucumbers and gherkins, prepared by vinegar	standard	18.00	f	\N	f	\N	t	f	f	KGS	90	seed	t	2026-02-12 05:04:04	2026-02-12 05:04:04
51	20091100	Frozen orange juice	standard	18.00	f	\N	f	\N	t	f	f	LTR	90	seed	t	2026-02-12 05:04:04	2026-02-12 05:04:04
52	21011100	Extracts of coffee	standard	18.00	f	\N	f	\N	t	f	f	KGS	90	seed	t	2026-02-12 05:04:04	2026-02-12 05:04:04
53	21012000	Extracts of tea	standard	18.00	f	\N	f	\N	t	f	f	KGS	90	seed	t	2026-02-12 05:04:04	2026-02-12 05:04:04
54	21050000	Ice cream	standard	18.00	f	\N	f	\N	t	f	f	LTR	90	seed	t	2026-02-12 05:04:04	2026-02-12 05:04:04
55	21069090	Food preparations not elsewhere specified	standard	18.00	f	\N	f	\N	t	f	f	KGS	90	seed	t	2026-02-12 05:04:04	2026-02-12 05:04:04
56	25231000	Cement clinkers	standard	18.00	f	\N	f	\N	t	f	f	TON	90	seed	t	2026-02-12 05:04:04	2026-02-12 05:04:04
57	25232100	White Portland cement	standard	18.00	f	\N	f	\N	t	f	f	TON	90	seed	t	2026-02-12 05:04:04	2026-02-12 05:04:04
58	25232900	Other Portland cement	standard	18.00	f	\N	f	\N	t	f	f	TON	90	seed	t	2026-02-12 05:04:04	2026-02-12 05:04:04
59	25233000	Aluminous cement	standard	18.00	f	\N	f	\N	t	f	f	TON	90	seed	t	2026-02-12 05:04:04	2026-02-12 05:04:04
60	25239000	Other hydraulic cements	standard	18.00	f	\N	f	\N	t	f	f	TON	90	seed	t	2026-02-12 05:04:04	2026-02-12 05:04:04
62	27101210	Motor spirit (petrol)	standard	17.00	f	\N	f	\N	f	f	f	LTR	90	seed	t	2026-02-12 05:04:04	2026-02-12 05:04:04
63	27101220	High speed diesel (HSD)	standard	17.00	f	\N	f	\N	f	f	f	LTR	90	seed	t	2026-02-12 05:04:04	2026-02-12 05:04:04
64	27101230	Light diesel oil (LDO)	standard	17.00	f	\N	f	\N	f	f	f	LTR	90	seed	t	2026-02-12 05:04:04	2026-02-12 05:04:04
65	27101240	JP-1 Kerosene type jet fuel	standard	17.00	f	\N	f	\N	f	f	f	LTR	90	seed	t	2026-02-12 05:04:04	2026-02-12 05:04:04
66	27101250	Superior kerosene oil (SKO)	standard	17.00	f	\N	f	\N	f	f	f	LTR	90	seed	t	2026-02-12 05:04:04	2026-02-12 05:04:04
67	27101290	Other light petroleum oils	standard	17.00	f	\N	f	\N	f	f	f	LTR	90	seed	t	2026-02-12 05:04:04	2026-02-12 05:04:04
68	27101910	Furnace oil	standard	17.00	f	\N	f	\N	f	f	f	LTR	90	seed	t	2026-02-12 05:04:04	2026-02-12 05:04:04
69	27101990	Other heavy petroleum oils	standard	17.00	f	\N	f	\N	f	f	f	LTR	90	seed	t	2026-02-12 05:04:04	2026-02-12 05:04:04
70	27111100	Liquefied natural gas (LNG)	standard	17.00	f	\N	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:04:04	2026-02-12 05:04:04
71	27111200	Liquefied propane (LPG)	standard	17.00	f	\N	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:04:04	2026-02-12 05:04:04
72	27111300	Liquefied butanes	standard	17.00	f	\N	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:04:04	2026-02-12 05:04:04
73	27112100	Natural gas in gaseous state	standard	17.00	f	\N	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:04:04	2026-02-12 05:04:04
74	27121000	Petroleum jelly	standard	18.00	f	\N	f	\N	t	f	f	KGS	90	seed	t	2026-02-12 05:04:04	2026-02-12 05:04:04
75	27132000	Petroleum bitumen	standard	18.00	f	\N	f	\N	f	f	f	TON	90	seed	t	2026-02-12 05:04:04	2026-02-12 05:04:04
76	28011000	Chlorine	standard	18.00	f	\N	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:04:04	2026-02-12 05:04:04
77	28030000	Carbon (carbon blacks)	standard	18.00	f	\N	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:04:04	2026-02-12 05:04:04
78	28070000	Sulphuric acid, oleum	standard	18.00	f	\N	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:04:04	2026-02-12 05:04:04
79	28080000	Nitric acid, sulphonitric acids	standard	18.00	f	\N	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:04:04	2026-02-12 05:04:04
80	28151100	Caustic soda (sodium hydroxide) solid	standard	18.00	f	\N	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:04:04	2026-02-12 05:04:04
81	28151200	Caustic soda in aqueous solution	standard	18.00	f	\N	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:04:04	2026-02-12 05:04:04
82	29011000	Saturated acyclic hydrocarbons	standard	18.00	f	\N	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:04:04	2026-02-12 05:04:04
83	29051100	Methanol (methyl alcohol)	standard	18.00	f	\N	f	\N	f	f	f	LTR	90	seed	t	2026-02-12 05:04:04	2026-02-12 05:04:04
89	32041100	Disperse dyes	standard	18.00	f	\N	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:04:04	2026-02-12 05:04:04
90	32091000	Paints and varnishes based on acrylic	standard	18.00	f	\N	f	\N	t	f	f	LTR	90	seed	t	2026-02-12 05:04:04	2026-02-12 05:04:04
91	33049100	Beauty and skin care preparations	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:04:04	2026-02-12 05:04:04
92	33051000	Shampoos	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:04:04	2026-02-12 05:04:04
93	34011100	Soap for toilet use	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:04:04	2026-02-12 05:04:04
94	34022000	Washing preparations, retail	standard	18.00	f	\N	f	\N	t	f	f	KGS	90	seed	t	2026-02-12 05:04:04	2026-02-12 05:04:04
95	38089100	Insecticides, packaged for retail	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:04:04	2026-02-12 05:04:04
96	38089200	Fungicides, packaged for retail	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:04:04	2026-02-12 05:04:04
97	38089300	Herbicides, packaged for retail	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:04:04	2026-02-12 05:04:04
98	39011000	Polyethylene, specific gravity <0.94	standard	18.00	f	\N	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:04:04	2026-02-12 05:04:04
99	39012000	Polyethylene, specific gravity >=0.94	standard	18.00	f	\N	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:04:04	2026-02-12 05:04:04
100	39021000	Polypropylene in primary forms	standard	18.00	f	\N	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:04:04	2026-02-12 05:04:04
101	39031100	Expandable polystyrene	standard	18.00	f	\N	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:04:04	2026-02-12 05:04:04
102	39041000	PVC, not mixed, in primary forms	standard	18.00	f	\N	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:04:04	2026-02-12 05:04:04
103	39076100	Polyethylene terephthalate (PET)	standard	18.00	f	\N	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:04:04	2026-02-12 05:04:04
104	39172300	PVC tubes, pipes and hoses	standard	18.00	f	\N	f	\N	f	f	f	MTR	90	seed	t	2026-02-12 05:04:04	2026-02-12 05:04:04
105	39201000	Plastic plates, sheets of ethylene polymers	standard	18.00	f	\N	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:04:04	2026-02-12 05:04:04
106	39231000	Plastic boxes, cases, crates	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:04:04	2026-02-12 05:04:04
107	39232100	Plastic sacks and bags of ethylene polymers	standard	18.00	f	\N	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:04:04	2026-02-12 05:04:04
108	39241000	Plastic tableware and kitchenware	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:04:04	2026-02-12 05:04:04
109	39269000	Other articles of plastics	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:04:04	2026-02-12 05:04:04
115	55032000	Polyester staple fibres	standard	18.00	f	\N	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
120	72131000	Hot-rolled bars/rods with indentations (rebars)	standard	18.00	f	\N	f	\N	f	f	f	TON	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
121	72132000	Other bars/rods of free-cutting steel	standard	18.00	f	\N	f	\N	f	f	f	TON	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
122	72139100	Bars/rods hot-rolled, circular cross-section <14mm	standard	18.00	f	\N	f	\N	f	f	f	TON	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
123	72139900	Other hot-rolled bars/rods of iron/steel	standard	18.00	f	\N	f	\N	f	f	f	TON	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
124	72141000	Forged bars/rods of iron/non-alloy steel	standard	18.00	f	\N	f	\N	f	f	f	TON	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
125	72142000	Bars/rods with indentations from rolling	standard	18.00	f	\N	f	\N	f	f	f	TON	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
126	72149900	Other bars/rods of iron/non-alloy steel	standard	18.00	f	\N	f	\N	f	f	f	TON	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
127	72161000	U, I, or H sections of iron/steel <80mm	standard	18.00	f	\N	f	\N	f	f	f	TON	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
128	72163100	U sections of iron/steel >=80mm	standard	18.00	f	\N	f	\N	f	f	f	TON	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
129	72163300	H sections of iron/steel >=80mm	standard	18.00	f	\N	f	\N	f	f	f	TON	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
130	72164000	L or T sections of iron/steel	standard	18.00	f	\N	f	\N	f	f	f	TON	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
131	72169900	Other angles, shapes, sections of iron/steel	standard	18.00	f	\N	f	\N	f	f	f	TON	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
132	72281000	Bars/rods of other alloy steel	standard	18.00	f	\N	f	\N	f	f	f	TON	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
133	72283000	Other bars/rods of alloy steel, hot-rolled	standard	18.00	f	\N	f	\N	f	f	f	TON	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
134	72285000	Other bars/rods of alloy steel, cold-formed	standard	18.00	f	\N	f	\N	f	f	f	TON	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
135	73081000	Bridges and bridge sections of iron/steel	standard	18.00	f	\N	f	\N	f	f	f	TON	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
136	73082000	Towers and lattice masts of iron/steel	standard	18.00	f	\N	f	\N	f	f	f	TON	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
137	73083000	Doors, windows and frames of iron/steel	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
138	73089000	Other structures and parts of iron/steel	standard	18.00	f	\N	f	\N	f	f	f	TON	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
139	73110000	Containers for compressed gas, of iron/steel	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
140	84011000	Nuclear reactors	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
141	84021100	Watertube boilers >45t/hr steam	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
142	84031000	Central heating boilers (not steam)	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
143	84071000	Aircraft engines, spark-ignition	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
144	84082000	Compression-ignition engines for vehicles	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
145	84099100	Parts for spark-ignition engines	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
146	84143000	Compressors for refrigerating equipment	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
147	84151000	Window/wall air conditioning units	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
148	84181000	Combined refrigerator-freezers	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
149	84182100	Compression-type refrigerators (household)	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
150	84221100	Dishwashing machines (household)	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
151	84501100	Fully automatic washing machines <=10kg	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
152	84713000	Portable digital automatic data processing machines (laptops)	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
153	84714100	Other digital data processing machines (desktops)	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
154	84716000	Input or output units for computers	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
155	84717000	Storage units for computers	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
156	84729000	Other office machines	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
157	84733000	Parts and accessories for computers	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
158	85013100	DC motors/generators 75-750W	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
159	85044000	Static converters (UPS, inverters)	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
160	85072000	Lead-acid accumulators (batteries)	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
161	85171100	Line telephone sets with cordless handset	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
162	85171200	Telephones for cellular networks (mobiles)	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
163	85176200	Machines for reception/conversion of data (routers/modems)	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
164	85258000	Television cameras and digital cameras	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
165	85287100	TV reception apparatus, not monitors/projectors	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
166	85287200	Other TV reception apparatus, colour	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
167	85441100	Winding wire of copper	standard	18.00	f	\N	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
168	85441900	Other winding wire	standard	18.00	f	\N	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
169	85442000	Coaxial cable and conductors	standard	18.00	f	\N	f	\N	f	f	f	MTR	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
170	85443000	Ignition wiring sets for vehicles/aircraft/ships	standard	18.00	f	\N	f	\N	f	f	f	SET	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
171	85444200	Electric conductors <=80V, with connectors	standard	18.00	f	\N	f	\N	f	f	f	MTR	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
172	85444900	Other electric conductors <=80V	standard	18.00	f	\N	f	\N	f	f	f	MTR	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
173	85446000	Electric conductors >1000V	standard	18.00	f	\N	f	\N	f	f	f	MTR	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
174	85447000	Optical fibre cables	standard	18.00	f	\N	f	\N	f	f	f	MTR	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
177	87021000	Motor vehicles for 10+ persons, diesel	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
178	87032100	Motor cars, spark-ignition <=1000cc	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
179	87032200	Motor cars, spark-ignition 1000-1500cc	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
180	87032300	Motor cars, spark-ignition 1500-3000cc	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
181	87032400	Motor cars, spark-ignition >3000cc	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
182	87033100	Motor cars, diesel <=1500cc	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
183	87041000	Dump trucks off-highway	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
184	87042100	Motor vehicles for goods, diesel <=5t	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
185	87042200	Motor vehicles for goods, diesel 5-20t	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
186	87043100	Motor vehicles for goods, spark <=5t	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
187	87071000	Bodies for passenger vehicles	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
188	87081000	Bumpers and parts for motor vehicles	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
189	87112000	Motorcycles 50-250cc	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
190	87141000	Parts of motorcycles	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
191	87141100	Saddles for motorcycles	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
192	87149400	Brakes and parts for bicycles	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
193	87150000	Baby carriages and parts	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
197	48025500	Uncoated paper 40-150g/m2	standard	18.00	f	\N	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
198	48191000	Cartons, boxes of corrugated paper	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
199	69089000	Glazed ceramic tiles	standard	18.00	f	\N	f	\N	t	f	f	SQM	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
200	70052100	Float glass, non-wired, coloured/opaque	standard	18.00	f	\N	f	\N	f	f	f	SQM	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
201	70109000	Glass bottles and containers	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
202	76011000	Unwrought aluminium, not alloyed	standard	18.00	f	\N	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
203	76042100	Aluminium alloy hollow profiles	standard	18.00	f	\N	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
204	76061100	Aluminium plates/sheets >0.2mm, rectangular	standard	18.00	f	\N	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
205	74031100	Cathodes of refined copper	standard	18.00	f	\N	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
206	74081100	Wire of refined copper, cross-section >6mm	standard	18.00	f	\N	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
207	40111000	New pneumatic tyres for motor cars	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
208	40112000	New pneumatic tyres for buses/lorries	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
209	40113000	New pneumatic tyres for aircraft	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
210	40114000	New pneumatic tyres for motorcycles	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
211	94011000	Seats, aircraft type	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
212	94013000	Swivel seats with variable height adjustment	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
213	94016100	Upholstered seats, wooden frame	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
214	94017100	Upholstered seats, metal frame	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
215	94031000	Metal office furniture	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
216	94036000	Other wooden furniture	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
217	94042100	Mattresses of cellular rubber or plastics	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
218	24011000	Tobacco, not stemmed/stripped	standard	18.00	f	\N	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
219	24012000	Tobacco, partly stemmed	standard	18.00	f	\N	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
220	24021000	Cigars and cigarillos	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
221	24022000	Cigarettes containing tobacco	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
222	22011000	Mineral waters and aerated waters	standard	18.00	f	\N	f	\N	t	f	f	LTR	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
223	22019000	Other waters, ice and snow	standard	18.00	f	\N	f	\N	t	f	f	LTR	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
224	22021000	Waters with added sugar or flavouring	standard	18.00	f	\N	f	\N	t	f	f	LTR	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
225	22029100	Non-alcoholic beverages (soft drinks)	standard	18.00	f	\N	f	\N	t	f	f	LTR	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
228	42021100	Trunks, suitcases of leather	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
231	42034000	Other leather accessories	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
232	64039900	Footwear of leather	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
233	22030000	Beer made from malt	standard	18.00	f	\N	f	\N	t	f	f	LTR	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
234	22041000	Sparkling wine	standard	18.00	f	\N	f	\N	t	f	f	LTR	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
235	22042100	Wine in containers <=2 litres	standard	18.00	f	\N	f	\N	t	f	f	LTR	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
236	22049000	Other fermented beverages	standard	18.00	f	\N	f	\N	t	f	f	LTR	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
237	22071000	Undenatured ethyl alcohol >=80%	standard	18.00	f	\N	f	\N	f	f	f	LTR	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
238	22082000	Spirits from grape wine or marc	standard	18.00	f	\N	f	\N	t	f	f	LTR	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
239	22089000	Other spirits and liqueurs	standard	18.00	f	\N	f	\N	t	f	f	LTR	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
242	25051000	Natural sand, silica sand	standard	18.00	f	\N	f	\N	f	f	f	TON	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
243	25059000	Other natural sands	standard	18.00	f	\N	f	\N	f	f	f	TON	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
244	25081000	Bentonite clay	standard	18.00	f	\N	f	\N	f	f	f	TON	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
246	25161100	Granite, crude or roughly trimmed	standard	18.00	f	\N	f	\N	f	f	f	TON	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
247	25161200	Granite, merely cut into blocks	standard	18.00	f	\N	f	\N	f	f	f	TON	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
248	25171000	Pebbles, gravel, broken stone	standard	18.00	f	\N	f	\N	f	f	f	TON	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
249	25201000	Gypsum, anhydrite	standard	18.00	f	\N	f	\N	f	f	f	TON	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
250	25221000	Quicklime	standard	18.00	f	\N	f	\N	f	f	f	TON	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
257	29021100	Cyclohexane	standard	18.00	f	\N	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
258	29022000	Benzene	standard	18.00	f	\N	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
259	29023000	Toluene	standard	18.00	f	\N	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
260	29024100	O-xylene	standard	18.00	f	\N	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
261	29051200	Propan-1-ol and propan-2-ol	standard	18.00	f	\N	f	\N	f	f	f	LTR	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
262	29053100	Ethylene glycol	standard	18.00	f	\N	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
263	29152100	Acetic acid	standard	18.00	f	\N	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
264	29161100	Acrylic acid and its salts	standard	18.00	f	\N	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
265	29221100	Monoethanolamine and its salts	standard	18.00	f	\N	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
266	29224200	Glutamic acid and its salts	standard	18.00	f	\N	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
267	29241100	Acyclic amides (meprobamate)	standard	18.00	f	\N	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
268	29332100	Hydantoin and its derivatives	standard	18.00	f	\N	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
269	29341000	Compounds with thiazole ring unfused	standard	18.00	f	\N	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
291	32041200	Acid dyes and preparations based thereon	standard	18.00	f	\N	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
292	32041300	Basic dyes and preparations based thereon	standard	18.00	f	\N	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
293	32041400	Direct dyes and preparations based thereon	standard	18.00	f	\N	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
294	32050000	Colour lakes, preparations based thereon	standard	18.00	f	\N	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
295	32061100	Pigments containing >=80% titanium dioxide	standard	18.00	f	\N	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
296	32082000	Paints based on acrylic or vinyl polymers	standard	18.00	f	\N	f	\N	t	f	f	LTR	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
297	32089000	Other paints and varnishes, non-aqueous	standard	18.00	f	\N	f	\N	t	f	f	LTR	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
298	32100000	Other paints and varnishes, prepared pigments	standard	18.00	f	\N	f	\N	t	f	f	LTR	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
299	32151100	Printing ink, black	standard	18.00	f	\N	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
300	33030000	Perfumes and toilet waters	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
301	33041000	Lip make-up preparations	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
302	33042000	Eye make-up preparations	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
303	33043000	Manicure or pedicure preparations	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
304	33052000	Preparations for permanent waving or straightening	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
305	33059000	Other hair preparations	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
306	33061000	Dentifrices (toothpaste)	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
307	33069000	Other oral hygiene preparations	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
308	33071000	Pre-shave, shaving and after-shave preparations	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
309	33072000	Personal deodorants and antiperspirants	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
310	34012000	Soap in other forms	standard	18.00	f	\N	f	\N	t	f	f	KGS	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
311	34021100	Anionic organic surface-active agents	standard	18.00	f	\N	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
312	34031100	Lubricant preparations with petroleum oils	standard	18.00	f	\N	f	\N	f	f	f	LTR	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
313	34031900	Other lubricant preparations	standard	18.00	f	\N	f	\N	f	f	f	LTR	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
314	34051000	Polishes for footwear	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
315	35061000	Adhesives for retail sale <=1kg	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
316	35069100	Adhesives based on rubber or plastics	standard	18.00	f	\N	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
317	36010000	Propellent powders	standard	18.00	f	\N	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
318	36050000	Matches	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
319	37011000	Photographic plates for X-ray	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
320	38011000	Artificial graphite	standard	18.00	f	\N	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
321	38140000	Organic composite solvents and thinners	standard	18.00	f	\N	f	\N	f	f	f	LTR	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
322	38220000	Diagnostic or laboratory reagents	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
323	38249100	Chemical preparations for electronics	standard	18.00	f	\N	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
324	40011000	Natural rubber latex	standard	18.00	f	\N	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
325	40021100	Styrene-butadiene rubber (SBR) latex	standard	18.00	f	\N	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
326	40091100	Rubber tubes and pipes, without fittings	standard	18.00	f	\N	f	\N	f	f	f	MTR	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
327	40101100	Conveyor belts of vulcanised rubber	standard	18.00	f	\N	f	\N	f	f	f	MTR	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
328	40119200	Tyres for agricultural or forestry vehicles	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
329	40119300	Tyres for construction or industrial vehicles	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
330	40131000	Inner tubes of rubber for motor cars	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
331	40132000	Inner tubes of rubber for bicycles	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
333	40169300	Gaskets, washers and other seals of rubber	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
334	41041100	Full grains bovine leather, unsplit	standard	18.00	f	\N	f	\N	f	f	f	SQM	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
335	41041900	Other bovine leather, tanned	standard	18.00	f	\N	f	\N	f	f	f	SQM	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
336	41044100	Full grains buffalo leather, unsplit	standard	18.00	f	\N	f	\N	f	f	f	SQM	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
337	41051000	Sheep or lamb skin leather, tanned	standard	18.00	f	\N	f	\N	f	f	f	SQM	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
338	41062100	Goat or kid skin leather, tanned	standard	18.00	f	\N	f	\N	f	f	f	SQM	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
339	42022100	Handbags with outer surface of leather	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
340	42023100	Wallets, purses of leather	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
341	42050000	Other articles of leather	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
344	44031100	Logs of teak, treated with paint/creosote	standard	18.00	f	\N	f	\N	f	f	f	TON	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
345	44032100	Logs of pine, in the rough	standard	18.00	f	\N	f	\N	f	f	f	TON	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
346	44071100	Pine lumber, sawn or chipped lengthwise	standard	18.00	f	\N	f	\N	f	f	f	TON	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
347	44072500	Dark red meranti, light red meranti lumber	standard	18.00	f	\N	f	\N	f	f	f	TON	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
348	44079100	Oak lumber, sawn or chipped	standard	18.00	f	\N	f	\N	f	f	f	TON	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
349	44101100	Particle board of wood	standard	18.00	f	\N	f	\N	f	f	f	SQM	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
350	44111200	Medium density fibreboard (MDF) <=5mm	standard	18.00	f	\N	f	\N	f	f	f	SQM	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
351	44111300	Medium density fibreboard (MDF) >5mm <=9mm	standard	18.00	f	\N	f	\N	f	f	f	SQM	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
352	44121000	Plywood of bamboo	standard	18.00	f	\N	f	\N	f	f	f	SQM	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
353	44123100	Plywood with tropical wood face ply	standard	18.00	f	\N	f	\N	f	f	f	SQM	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
354	44181000	Windows, French-windows, frames of wood	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
355	44182000	Doors and their frames of wood	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
356	47010000	Mechanical wood pulp	standard	18.00	f	\N	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
357	47032100	Chemical wood pulp, soda or sulphate, coniferous	standard	18.00	f	\N	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
359	48021000	Handmade paper and paperboard	standard	18.00	f	\N	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
360	48041100	Unbleached kraftliner, uncoated	standard	18.00	f	\N	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
361	48051100	Semi-chemical fluting paper	standard	18.00	f	\N	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
362	48101300	Paper and paperboard coated with kaolin	standard	18.00	f	\N	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
363	48142000	Wallpaper and wall coverings	standard	18.00	f	\N	f	\N	t	f	f	SQM	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
364	48181000	Toilet paper	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
365	48182000	Handkerchiefs, cleansing or facial tissues	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
366	48192000	Folding cartons, boxes of non-corrugated paper	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
367	48211000	Printed labels of paper	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
372	49111000	Trade advertising material, catalogues	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
374	50040000	Silk yarn (other than spun from waste)	standard	18.00	f	\N	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
375	50071000	Woven fabrics of noil silk	standard	18.00	f	\N	f	\N	f	f	f	SQM	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
378	51051000	Carded wool	standard	18.00	f	\N	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
379	51052000	Combed wool (wool tops)	standard	18.00	f	\N	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
380	51061000	Yarn of carded wool, >=85% wool	standard	18.00	f	\N	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
381	51111100	Woven fabrics of carded wool, >=85% wool	standard	18.00	f	\N	f	\N	f	f	f	SQM	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
384	54011000	Sewing thread of synthetic filaments	standard	18.00	f	\N	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
385	54021000	High tenacity yarn of nylon/polyamides	standard	18.00	f	\N	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
386	54024600	Polyester filament yarn, partially oriented	standard	18.00	f	\N	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
387	54071000	Woven fabrics of high tenacity nylon yarn	standard	18.00	f	\N	f	\N	f	f	f	SQM	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
388	55013000	Polyester staple fibre tow	standard	18.00	f	\N	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
389	55091100	Yarn of polyester staple fibres, >=85%	standard	18.00	f	\N	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
390	55151100	Woven fabrics of polyester with viscose rayon	standard	18.00	f	\N	f	\N	f	f	f	SQM	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
391	55161100	Woven fabrics of artificial staple fibres	standard	18.00	f	\N	f	\N	f	f	f	SQM	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
392	56012100	Wadding of cotton and articles thereof	standard	18.00	f	\N	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
393	56031100	Nonwovens of man-made filaments <=25g/m2	standard	18.00	f	\N	f	\N	f	f	f	SQM	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
394	56074900	Twine, cordage and ropes of polyethylene	standard	18.00	f	\N	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
395	57011000	Carpets of wool or fine animal hair, knotted	standard	18.00	f	\N	f	\N	t	f	f	SQM	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
396	57029200	Carpets of man-made textile materials	standard	18.00	f	\N	f	\N	t	f	f	SQM	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
397	57033000	Tufted carpets of man-made textile materials	standard	18.00	f	\N	f	\N	t	f	f	SQM	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
398	58011000	Woven pile fabrics of wool	standard	18.00	f	\N	f	\N	f	f	f	SQM	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
399	59031000	Textile fabrics impregnated with PVC	standard	18.00	f	\N	f	\N	f	f	f	SQM	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
400	59039000	Other textile fabrics impregnated or coated	standard	18.00	f	\N	f	\N	f	f	f	SQM	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
401	60011000	Knitted pile fabrics (long pile)	standard	18.00	f	\N	f	\N	f	f	f	SQM	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
402	60062200	Other knitted fabrics of cotton, dyed	standard	18.00	f	\N	f	\N	f	f	f	SQM	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
403	61051000	Mens shirts of cotton, knitted	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
404	61061000	Womens blouses of cotton, knitted	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
405	61099000	T-shirts of other textile materials, knitted	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
406	61103000	Jerseys, pullovers of man-made fibres	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
407	61151000	Graduated compression hosiery	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
408	62019300	Mens anoraks, windbreakers of man-made fibres	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
409	62031100	Mens suits of wool or fine animal hair	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
410	62033100	Mens jackets and blazers of wool	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
411	62034100	Mens trousers of wool	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
412	62034300	Mens trousers of synthetic fibres	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
413	62052000	Mens shirts of cotton, not knitted	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
414	62053000	Mens shirts of man-made fibres, not knitted	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
415	63039100	Curtains of cotton, not knitted	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
416	63053300	Sacks and bags of polyethylene strips	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
417	64021200	Ski boots, cross-country ski footwear	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
418	64031200	Ski boots with outer sole and upper of leather	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
419	64041100	Sports footwear with rubber/plastic soles	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
420	64041900	Other footwear with textile uppers	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
421	64051000	Other footwear with uppers of leather	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
422	64061000	Uppers and parts thereof of leather	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
423	65010000	Hat-forms, hat bodies, hood and plateaux	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
424	65050000	Hats and other headgear, knitted	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
425	66019100	Umbrellas with telescopic shaft	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
426	67041100	Wigs of synthetic textile materials	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
427	68010000	Setts, curbstones, flagstones of natural stone	standard	18.00	f	\N	f	\N	f	f	f	TON	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
428	68022300	Granite, cut or sawn, with flat surface	standard	18.00	f	\N	f	\N	f	f	f	SQM	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
429	68029100	Marble, travertine, alabaster worked	standard	18.00	f	\N	f	\N	f	f	f	SQM	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
430	68091100	Boards and sheets of plaster, not faced	standard	18.00	f	\N	f	\N	f	f	f	SQM	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
431	68101100	Building blocks of cement or concrete	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
432	68114000	Articles of asbestos-cement	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
433	69071000	Unglazed ceramic flags and tiles	standard	18.00	f	\N	f	\N	t	f	f	SQM	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
434	69072100	Glazed ceramic tiles, water absorption <=0.5%	standard	18.00	f	\N	f	\N	t	f	f	SQM	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
435	69091100	Ceramic wares for laboratory use	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
436	69111000	Tableware and kitchenware of porcelain	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
437	69120000	Ceramic tableware, other than porcelain	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
438	69141000	Other articles of porcelain or china	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
439	70031200	Wired sheets of cast or rolled glass	standard	18.00	f	\N	f	\N	f	f	f	SQM	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
440	70042000	Drawn glass, coloured, opacified	standard	18.00	f	\N	f	\N	f	f	f	SQM	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
441	70051000	Float glass, non-wired, with absorbent layer	standard	18.00	f	\N	f	\N	f	f	f	SQM	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
442	70060000	Glass, bent, edge-worked, engraved	standard	18.00	f	\N	f	\N	f	f	f	SQM	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
443	70071100	Toughened safety glass for vehicles	standard	18.00	f	\N	f	\N	f	f	f	SQM	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
444	70091000	Rear-view mirrors for vehicles	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
445	70131000	Glassware of glass-ceramics	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
446	70134100	Drinking glasses of lead crystal	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
447	70140000	Signalling glassware and optical elements	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
449	71069200	Silver, semi-manufactured	standard	18.00	f	\N	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
452	71131100	Jewellery of silver	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
453	71131900	Jewellery of other precious metals	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
454	71171100	Cuff-links and studs of base metal	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
456	72071100	Semi-finished products of iron, carbon <0.25%	standard	18.00	f	\N	f	\N	f	f	f	TON	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
457	72081000	Flat-rolled products of iron, in coils, hot-rolled	standard	18.00	f	\N	f	\N	f	f	f	TON	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
458	72091700	Flat-rolled products of iron, cold-rolled >=0.5mm	standard	18.00	f	\N	f	\N	f	f	f	TON	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
459	72101100	Tinplate, flat-rolled iron/steel	standard	18.00	f	\N	f	\N	f	f	f	TON	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
460	72104900	Other flat-rolled iron/steel, zinc-plated	standard	18.00	f	\N	f	\N	f	f	f	TON	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
461	72171000	Wire of iron/non-alloy steel, not plated	standard	18.00	f	\N	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
462	73044100	Cold-drawn seamless tubes of stainless steel	standard	18.00	f	\N	f	\N	f	f	f	MTR	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
463	73063000	Other welded tubes of iron/steel, circular	standard	18.00	f	\N	f	\N	f	f	f	MTR	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
464	73071100	Cast fittings of non-malleable cast iron	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
465	73101000	Tanks, casks, drums of iron/steel 50-300L	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
466	73141200	Endless bands of stainless steel for machines	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
467	73170000	Nails, tacks, drawing pins of iron/steel	standard	18.00	f	\N	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
468	73181500	Other screws and bolts of iron/steel	standard	18.00	f	\N	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
469	73182100	Spring washers of iron/steel	standard	18.00	f	\N	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
470	73201000	Leaf springs and leaves of iron/steel	standard	18.00	f	\N	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
471	73211100	Cooking appliances of iron/steel, gas fuel	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
472	73239300	Table/kitchen articles of stainless steel	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
473	73261900	Other articles of iron/steel, forged	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
474	74031900	Other refined copper, unwrought	standard	18.00	f	\N	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
475	74061000	Copper powders of non-lamellar structure	standard	18.00	f	\N	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
476	74071000	Bars, rods and profiles of refined copper	standard	18.00	f	\N	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
477	74091100	Plates, sheets of refined copper, in coils	standard	18.00	f	\N	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
478	74111000	Tubes and pipes of refined copper	standard	18.00	f	\N	f	\N	f	f	f	MTR	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
479	76012000	Unwrought aluminium alloys	standard	18.00	f	\N	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
480	76041000	Aluminium bars, rods and profiles, not alloyed	standard	18.00	f	\N	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
481	76051100	Aluminium wire, not alloyed, max cross-section >7mm	standard	18.00	f	\N	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
482	76071100	Aluminium foil, not backed, rolled <=0.2mm	standard	18.00	f	\N	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
483	76082000	Aluminium alloy tubes and pipes	standard	18.00	f	\N	f	\N	f	f	f	MTR	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
484	76090000	Aluminium tube or pipe fittings	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
485	76151000	Aluminium table/kitchen articles	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
486	76161000	Nails, tacks, staples of aluminium	standard	18.00	f	\N	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
487	82011000	Spades and shovels	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
488	82019000	Other hand tools for agriculture	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
489	82032000	Pliers, pincers and similar tools	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
490	82041100	Hand-operated spanners and wrenches	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
491	82051000	Drilling, threading or tapping tools	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
492	82071100	Rock drilling or earth boring tools, cermet	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
493	82089000	Other knives and cutting blades for machines	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
494	82100000	Hand-operated mechanical appliances for food	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
495	82119300	Knives with fixed blades	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
496	82121000	Razors	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
497	82152000	Sets of assorted spoons, forks, ladles	standard	18.00	f	\N	f	\N	t	f	f	SET	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
498	83011000	Padlocks of base metal	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
499	83021000	Hinges of base metal	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
500	83024100	Mountings and fittings for buildings	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
501	83062100	Statuettes and ornaments, plated with precious metal	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
502	83089000	Clasps, buckles, hooks of base metal	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
503	84051000	Producer gas or water gas generators	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
504	84061000	Steam turbines for marine propulsion	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
505	84111100	Turbo-jets of a thrust <=25kN	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
506	84131100	Pumps for dispensing fuel or lubricants	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
507	84137000	Other centrifugal pumps	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
508	84145100	Table, floor, wall ceiling fans <=125W	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
509	84186100	Heat pumps other than air conditioning	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
510	84191100	Instantaneous gas water heaters	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
511	84213100	Intake air filters for internal combustion engines	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
512	84261100	Overhead travelling cranes on fixed support	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
513	84271000	Self-propelled trucks, electric motor	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
514	84311000	Parts of pulleys, winches, cranes	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
516	84332000	Mowers for lawns, parks or sports-grounds	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
518	84381000	Bakery machinery	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
519	84401000	Book-binding machinery	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
520	84431100	Offset printing machinery, reel fed	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
521	84483100	Card clothing for textile machines	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
522	84521000	Household sewing machines	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
523	84581100	Horizontal lathes for metal	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
524	84621000	Forging or die-stamping machines	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
525	85011000	Electric motors of output <=37.5W	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
526	85015100	AC motors, multi-phase, 750W-75kW	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
527	85021100	Generating sets with diesel engine <=75kVA	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
528	85030000	Parts for electric motors and generators	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
529	85041000	Ballasts for discharge lamps	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
530	85043100	Transformers, output <=1kVA	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
531	85061000	Manganese dioxide primary cells and batteries	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
532	85065000	Lithium primary cells and batteries	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
533	85071000	Lead-acid accumulators for starting piston engines	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
534	85076000	Lithium-ion accumulators	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
535	85141000	Resistance heated furnaces and ovens	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
536	85161000	Electric instantaneous water heaters	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
537	85163100	Electric hair dryers	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
538	85164000	Electric smoothing irons	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
539	85166000	Electric ovens, cookers, cooking plates	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
540	85167100	Electro-thermic coffee or tea makers	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
541	85167900	Other electro-thermic appliances	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
542	85181000	Microphones and stands therefor	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
543	85182100	Single loudspeakers in enclosures	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
544	85192000	Apparatus operated by coins/tokens	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
545	85211000	Magnetic tape video recording apparatus	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
546	85231000	Unrecorded magnetic tapes, cards	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
547	85252000	Transmission apparatus with reception apparatus	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
548	85281200	Colour television receivers, LCD/LED	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
549	85311000	Burglar or fire alarms	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
550	85361000	Fuses for voltage <=1000V	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
551	85371000	Boards, panels for voltage <=1000V	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
552	85392100	Tungsten halogen filament lamps	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
553	85395000	Light-emitting diode (LED) lamps	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
554	86071100	Driving bogies and bissel-bogies for railways	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
555	86071900	Other bogies and bissel-bogies for railways	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
558	87060000	Chassis fitted with engines for motor vehicles	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
559	87082100	Safety seat belts for motor vehicles	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
560	87083000	Brakes and servo-brakes for motor vehicles	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
561	87084000	Gear boxes for motor vehicles	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
562	87085000	Drive-axles with differential for motor vehicles	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
563	87087000	Road wheels and parts for motor vehicles	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
564	87089100	Radiators for motor vehicles	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
565	87091100	Electric vehicles, works trucks, no lifting	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
566	87100000	Tanks and other armoured fighting vehicles	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
567	88021200	Helicopters, unladen weight >2000kg	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
568	88023000	Aeroplanes, unladen weight <=2000kg	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
569	90011000	Optical fibres, optical fibre bundles	standard	18.00	f	\N	f	\N	f	f	f	MTR	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
570	90021100	Objective lenses for cameras/projectors	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
571	90031100	Frames for spectacles, of plastics	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
572	90041000	Sunglasses	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
573	90051000	Binoculars	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
574	90111000	Stereoscopic microscopes	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
575	90121000	Microscopes other than optical	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
576	90141000	Direction finding compasses	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
577	90158000	Other surveying instruments	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
586	90261000	Instruments for measuring flow/level of liquids	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
587	90271000	Gas or smoke analysis apparatus	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
588	90281000	Gas meters	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
589	90291000	Revolution counters, production counters	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
590	90303100	Multimeters without recording device	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
591	91011100	Wrist watches with mechanical display	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
592	91021100	Wrist watches, electronic display only	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
593	91051100	Alarm clocks, electrically operated	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
594	94012000	Seats for motor vehicles	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
595	94032000	Other metal furniture	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
596	94034000	Wooden furniture for kitchen use	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
597	94035000	Wooden furniture for bedroom use	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
598	94039000	Parts of furniture of plastics	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
599	94049000	Other articles of bedding and similar	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
600	94051000	Chandeliers and other ceiling fittings	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
601	94052000	Electric table, desk, bedside lamps	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
602	94054000	Other electric lamps and lighting fittings	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
603	94060000	Prefabricated buildings	standard	18.00	f	\N	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
604	95030000	Tricycles, scooters, pedal cars and similar toys	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
605	95042000	Articles for billiards	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
606	95051000	Christmas decorations	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
607	95069100	Articles for gymnastics or athletics	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
608	96081000	Ball point pens	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
609	96082000	Felt tipped and porous tipped pens	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
610	96091000	Pencils and crayons, with leads	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
611	96170000	Vacuum flasks and other vacuum vessels	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
612	96190000	Sanitary towels, napkins, tampons and similar	standard	18.00	f	\N	f	\N	t	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
1	02011000	Carcasses and half-carcasses of bovine animals, fresh or chilled	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:04:04	2026-02-12 05:04:04
2	02012000	Cuts of bovine meat with bone in, fresh or chilled	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:04:04	2026-02-12 05:04:04
3	02013000	Boneless meat of bovine animals, fresh or chilled	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:04:04	2026-02-12 05:04:04
4	02021000	Carcasses and half-carcasses of bovine animals, frozen	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:04:04	2026-02-12 05:04:04
5	02023000	Boneless meat of bovine animals, frozen	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:04:04	2026-02-12 05:04:04
6	02031100	Carcasses and half-carcasses of swine, fresh or chilled	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:04:04	2026-02-12 05:04:04
7	02041000	Carcasses and half-carcasses of sheep, fresh or chilled	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:04:04	2026-02-12 05:04:04
8	02042100	Carcasses and half-carcasses of sheep, frozen	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:04:04	2026-02-12 05:04:04
9	02044100	Carcasses and half-carcasses of goats, fresh or chilled	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:04:04	2026-02-12 05:04:04
10	02050000	Meat of horses, asses, mules or hinnies	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:04:04	2026-02-12 05:04:04
11	02071100	Whole chicken, not cut in pieces, fresh or chilled	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:04:04	2026-02-12 05:04:04
12	02071200	Whole chicken, not cut in pieces, frozen	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:04:04	2026-02-12 05:04:04
13	03011100	Live ornamental freshwater fish	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:04:04	2026-02-12 05:04:04
14	03021100	Trout, fresh or chilled	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:04:04	2026-02-12 05:04:04
15	03031100	Sockeye salmon, frozen	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:04:04	2026-02-12 05:04:04
16	04011000	Milk not concentrated, fat <=1%	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	LTR	90	seed	t	2026-02-12 05:04:04	2026-02-12 05:04:04
17	04012000	Milk not concentrated, fat 1-6%	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	LTR	90	seed	t	2026-02-12 05:04:04	2026-02-12 05:04:04
18	04014000	Milk not concentrated, fat >6%	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	LTR	90	seed	t	2026-02-12 05:04:04	2026-02-12 05:04:04
19	04021000	Milk and cream, concentrated, powdered <=1.5% fat	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:04:04	2026-02-12 05:04:04
20	04022100	Milk powder, not sweetened, fat >1.5%	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:04:04	2026-02-12 05:04:04
21	04031000	Yogurt	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:04:04	2026-02-12 05:04:04
22	04041000	Whey and modified whey	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:04:04	2026-02-12 05:04:04
23	04051000	Butter	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:04:04	2026-02-12 05:04:04
24	04061000	Fresh cheese	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:04:04	2026-02-12 05:04:04
25	04070000	Birds eggs in shell	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:04:04	2026-02-12 05:04:04
26	07019000	Potatoes, fresh or chilled	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:04:04	2026-02-12 05:04:04
27	07020000	Tomatoes, fresh or chilled	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:04:04	2026-02-12 05:04:04
28	07031000	Onions and shallots	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:04:04	2026-02-12 05:04:04
29	08051000	Oranges, fresh or dried	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:04:04	2026-02-12 05:04:04
30	08061000	Grapes, fresh	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:04:04	2026-02-12 05:04:04
31	08071100	Watermelons	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:04:04	2026-02-12 05:04:04
32	10011100	Durum wheat seed	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:04:04	2026-02-12 05:04:04
33	10019900	Wheat other	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:04:04	2026-02-12 05:04:04
34	10061000	Rice in the husk (paddy)	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:04:04	2026-02-12 05:04:04
35	10063000	Semi-milled or wholly milled rice	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:04:04	2026-02-12 05:04:04
36	11010000	Wheat or meslin flour	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:04:04	2026-02-12 05:04:04
37	11022000	Maize (corn) flour	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:04:04	2026-02-12 05:04:04
38	15071000	Crude soybean oil	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	LTR	90	seed	t	2026-02-12 05:04:04	2026-02-12 05:04:04
40	15111000	Crude palm oil	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	LTR	90	seed	t	2026-02-12 05:04:04	2026-02-12 05:04:04
42	15121100	Crude sunflower-seed oil	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	LTR	90	seed	t	2026-02-12 05:04:04	2026-02-12 05:04:04
43	15141100	Crude rapeseed oil	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	LTR	90	seed	t	2026-02-12 05:04:04	2026-02-12 05:04:04
44	17011400	Raw cane sugar, not refined	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:04:04	2026-02-12 05:04:04
45	17019910	Refined sugar	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:04:04	2026-02-12 05:04:04
46	19011000	Preparations for infant use	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:04:04	2026-02-12 05:04:04
61	27090010	Crude petroleum oil	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	LTR	90	seed	t	2026-02-12 05:04:04	2026-02-12 05:04:04
84	30049000	Other medicaments, packaged for retail	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:04:04	2026-02-12 05:04:04
85	31021000	Urea fertilizer	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:04:04	2026-02-12 05:04:04
86	31031100	Superphosphates containing >=35%	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:04:04	2026-02-12 05:04:04
87	31042000	Potassium chloride fertilizer	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:04:04	2026-02-12 05:04:04
88	31052000	NPK fertilizer	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:04:04	2026-02-12 05:04:04
110	52010000	Cotton, not carded or combed	zero_rated	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:04:04	2026-02-12 05:04:04
111	52030000	Cotton, carded or combed	zero_rated	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:04:04	2026-02-12 05:04:04
112	52051100	Cotton yarn, single, uncombed >=714.29	zero_rated	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:04:04	2026-02-12 05:04:04
113	52052100	Cotton yarn, single, combed >=714.29	zero_rated	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
114	52081100	Cotton fabric, plain weave <=100g/m2	zero_rated	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	SQM	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
116	61091000	T-shirts, knitted, of cotton	zero_rated	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
117	62034200	Mens trousers, of cotton	zero_rated	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
118	63021000	Bed linen, knitted	zero_rated	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
119	63026000	Toilet linen and kitchen linen	zero_rated	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
175	87011000	Pedestrian controlled tractors	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
176	87019100	Agricultural tractors <=18kW	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
194	90189000	Other medical/surgical instruments	zero_rated	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
195	90211000	Orthopaedic and fracture appliances	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
196	48010000	Newsprint, in rolls or sheets	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
226	85414000	Photovoltaic cells (solar panels)	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
227	41012000	Whole bovine hides <=8kg	zero_rated	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
229	42031000	Articles of apparel of leather	zero_rated	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
230	42032100	Gloves of leather, sports	zero_rated	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:04:05	2026-02-12 05:04:05
240	25010010	Table salt	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
241	25010090	Other salt, pure sodium chloride	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
245	25101000	Natural calcium phosphates, unground	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	TON	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
251	26011100	Non-agglomerated iron ores and concentrates	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	TON	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
252	26011200	Agglomerated iron ores and concentrates	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	TON	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
253	26030000	Copper ores and concentrates	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	TON	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
254	26070000	Lead ores and concentrates	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	TON	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
255	26080000	Zinc ores and concentrates	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	TON	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
256	26100000	Chromium ores and concentrates	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	TON	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
270	29362100	Vitamins A and their derivatives	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
271	29362500	Vitamin B6 and its derivatives	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
272	29362700	Vitamin C and its derivatives	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
273	29362800	Vitamin E and its derivatives	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
274	29371100	Somatotropin (growth hormone)	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
275	29372100	Cortisone and hydrocortisone	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
276	29411000	Penicillins and their derivatives	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
277	29412000	Streptomycins and their derivatives	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
278	30012000	Extracts of glands for organo-therapeutic uses	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
279	30021200	Antisera and other blood fractions	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
280	30021300	Immunological products unmixed	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
281	30022000	Vaccines for human medicine	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
282	30031000	Medicaments containing penicillins	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
283	30032000	Medicaments containing antibiotics	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
284	30033100	Medicaments containing insulin	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
285	30041000	Medicaments with penicillins, for retail	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
286	30042000	Medicaments with antibiotics, for retail	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
287	30043100	Medicaments containing insulin, for retail	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
288	30051000	Adhesive dressings and bandages	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
289	30059000	Wadding, gauze, bandages, surgical catgut	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
290	30061000	Sterile surgical catgut, suture materials	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
332	40151100	Surgical rubber gloves	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
342	44011100	Fuel wood in logs	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	TON	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
343	44012100	Wood in chips, coniferous	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	TON	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
358	47050000	Recovered (waste and scrap) paper	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
368	49011000	Printed books, brochures, leaflets	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
369	49021000	Newspapers, journals appearing >=4 times a week	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
370	49030000	Childrens picture, drawing or colouring books	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
371	49070000	Unused postage stamps, stamp-impressed paper	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
373	50020000	Raw silk (not thrown)	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
376	51011100	Greasy shorn wool, not carded or combed	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
377	51012100	Degreased shorn wool, not carbonised	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:15:25	2026-02-12 05:15:25
382	53011000	Flax, raw or retted	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
383	53050000	Coconut, abaca, ramie fibres	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
448	71069100	Silver, unwrought	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
450	71081200	Gold in unwrought non-monetary forms	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
451	71081300	Gold in semi-manufactured forms	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	KGS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
455	72041000	Waste and scrap of cast iron	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	TON	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
515	84321000	Ploughs for agriculture	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
517	84361000	Machinery for preparing animal feeds	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
556	87019200	Agricultural tractors 18-37kW	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
557	87019300	Agricultural tractors 37-75kW	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
578	90181100	Electro-cardiographs	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
579	90181200	Ultrasonic scanning apparatus	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
580	90181300	Magnetic resonance imaging apparatus	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
581	90181900	Other electro-diagnostic apparatus	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
582	90191000	Mechano-therapy appliances, massage apparatus	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
583	90192000	Ozone therapy, oxygen therapy apparatus	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
584	90221400	X-ray apparatus for dental uses	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
585	90251100	Clinical thermometers, liquid-filled	exempt	0.00	t	SRO 551(I)/2008	f	\N	f	f	f	NOS	90	seed	t	2026-02-12 05:15:26	2026-02-12 05:15:26
\.


--
-- Data for Name: hs_rejection_history; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.hs_rejection_history (id, hs_code, rejection_count, last_rejection_reason, last_seen_at, updated_at, error_code, error_message, last_rejected_at, environment) FROM stdin;
3	22021010	7	FBR rejection: SQLSTATE[23502]: Not null violation: 7 ERROR:  null value in column "customer_ntn" of relation "customer_ledgers" violates not-null constraint\nDETAIL:  Failing row contains (9, 2, WALK IN CUSTOMER, null, 8, 70.80, 0.00, 70.80, invoice, Invoice DEMOT-000001 locked, 2026-02-12 11:48:46, 2026-02-12 11:48:46). (Connection: pgsql, Host: helium, Port: 5432, Database: heliumdb, SQL: insert into "customer_ledgers" ("company_id", "customer_name", "customer_ntn", "invoice_id", "debit", "credit", "balance_after", "type", "notes", "updated_at", "created_at") values (2, WALK IN CUSTOMER, ?, 8, 70.80, 0, 70.8, invoice, Invoice DEMOT-000001 locked, 2026-02-12 11:48:46, 2026-02-12 11:48:46) returning "id")	2026-02-12 11:48:46	2026-02-12 11:48:46	exception	SQLSTATE[23502]: Not null violation: 7 ERROR:  null value in column "customer_ntn" of relation "customer_ledgers" violates not-null constraint\nDETAIL:  Failing row contains (9, 2, WALK IN CUSTOMER, null, 8, 70.80, 0.00, 70.80, invoice, Invoice DEMOT-000001 locked, 2026-02-12 11:48:46, 2026-02-12 11:48:46). (Connection: pgsql, Host: helium, Port: 5432, Database: heliumdb, SQL: insert into "customer_ledgers" ("company_id", "customer_name", "customer_ntn", "invoice_id", "debit", "credit", "balance_after", "type", "notes", "updated_at", "created_at") values (2, WALK IN CUSTOMER, ?, 8, 70.80, 0, 70.8, invoice, Invoice DEMOT-000001 locked, 2026-02-12 11:48:46, 2026-02-12 11:48:46) returning "id")	2026-02-12 11:48:46	sandbox
4	31053000	39	FBR rejection: Item 1: [0102] Provided sales tax amount does not match the calculated sales tax amount in case of 3rd schedule goods. Please ensure that the Fixed/Notified Value or Retail Price is used to calculated the Sales Tax Amount for the provided rate.	2026-02-15 14:49:21	2026-02-15 14:49:21	validation_error	Item 1: [0102] Provided sales tax amount does not match the calculated sales tax amount in case of 3rd schedule goods. Please ensure that the Fixed/Notified Value or Retail Price is used to calculated the Sales Tax Amount for the provided rate.	2026-02-15 14:49:21	production
5	25239090	1	FBR rejection: [0052] HS Code does not match with provided sale type. Please refer to relevant reference API in the technical document for DI API for valid HS Code against sale type.	2026-02-17 09:27:23	2026-02-17 09:27:23	validation_error	[0052] HS Code does not match with provided sale type. Please refer to relevant reference API in the technical document for DI API for valid HS Code against sale type.	2026-02-17 09:27:23	production
\.


--
-- Data for Name: hs_unmapped_log; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.hs_unmapped_log (id, hs_code, company_id, invoice_id, frequency_count, first_seen_at, last_seen_at, created_at, updated_at) FROM stdin;
3	3105	2	\N	2	2026-02-12 02:46:14	2026-02-12 02:46:14	2026-02-12 02:46:14	2026-02-12 02:46:14
2	31053000	2	\N	6	2026-02-12 02:19:43	2026-02-12 02:47:58	2026-02-12 02:19:43	2026-02-12 02:47:58
4	22021010	2	8	10	2026-02-12 05:56:33	2026-02-12 08:50:20	2026-02-12 05:56:33	2026-02-12 08:50:20
5	3105	7	\N	2	2026-02-13 05:29:52	2026-02-13 05:29:52	2026-02-13 05:29:52	2026-02-13 05:29:52
8	25239090	7	29	2	2026-02-17 09:26:29	2026-02-17 09:26:29	2026-02-17 09:26:29	2026-02-17 09:26:29
6	31053000	7	30	66	2026-02-13 05:30:09	2026-02-17 09:36:40	2026-02-13 05:30:09	2026-02-17 09:36:40
7	35013000	7	\N	2	2026-02-14 06:17:45	2026-02-14 06:17:45	2026-02-14 06:17:45	2026-02-14 06:17:45
\.


--
-- Data for Name: hs_unmapped_queue; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.hs_unmapped_queue (id, hs_code, company_id, usage_count, first_seen_at, flagged_reason, created_at, updated_at) FROM stdin;
1	22021010	2	3	2026-02-12 05:56:33	Not in master	2026-02-12 05:56:33	2026-02-12 07:05:54
2	3105	7	1	2026-02-13 05:29:52	Not in master	2026-02-13 05:29:52	2026-02-13 05:29:52
4	35013000	7	1	2026-02-14 06:17:45	Not in master	2026-02-14 06:17:45	2026-02-14 06:17:45
3	31053000	7	7	2026-02-13 05:30:09	Not in master	2026-02-13 05:30:09	2026-02-14 16:45:07
\.


--
-- Data for Name: hs_usage_patterns; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.hs_usage_patterns (id, hs_code, schedule_type, tax_rate, sro_schedule_no, sro_item_serial_no, mrp_required, sale_type, success_count, rejection_count, confidence_score, admin_status, last_used_at, integrity_hash, created_at, updated_at) FROM stdin;
2	2523.9090	3rd_schedule	18.00	\N	\N	f	\N	0	1	0.00	auto	\N	c44fbf74886635d3df1875c20fa3cb3b5c053f29523e17d5846bb91624299e94	2026-02-17 09:27:23	2026-02-17 09:27:23
1	3105.3000	3rd_schedule	5.00	3rd Schedule goods	51	t	3rd Schedule Goods	9	21	0.00	approved	2026-02-17 09:36:48	6036df218938ebb76717b020a4d8dd675c9860c80eeba39f151e638c03b281fc	2026-02-14 06:23:03	2026-02-17 09:36:48
\.


--
-- Data for Name: inventory_adjustments; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.inventory_adjustments (id, company_id, product_id, type, quantity, previous_quantity, new_quantity, reason, notes, created_by, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: inventory_movements; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.inventory_movements (id, company_id, product_id, branch_id, type, quantity, unit_price, total_price, balance_after, reference_type, reference_id, reference_number, notes, created_by, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: inventory_stocks; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.inventory_stocks (id, company_id, product_id, branch_id, quantity, min_stock_level, max_stock_level, avg_purchase_price, last_purchase_price, created_at, updated_at) FROM stdin;
3	13	25	\N	-1.00	0.00	\N	0.00	0.00	2026-03-24 16:54:23	2026-03-24 16:54:23
\.


--
-- Data for Name: invoice_activity_logs; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.invoice_activity_logs (id, invoice_id, company_id, user_id, action, changes_json, ip_address, created_at) FROM stdin;
1	1	1	2	created	{"buyer_name":"ABC Traders","total_amount":15000}	127.0.0.1	2026-02-10 10:22:41
2	1	1	2	submitted	\N	127.0.0.1	2026-02-10 12:22:41
183	18	7	10	submitted	{"mode":"smart","compliance_score":55,"risk_level":"HIGH"}	10.83.7.79	2026-02-15 14:45:04
4	3	1	2	created	{"buyer_name":"Global Logistics","total_amount":45000}	127.0.0.1	2026-02-01 10:22:41
5	3	1	2	submitted	\N	127.0.0.1	2026-02-01 12:22:41
6	3	1	\N	locked	{"fbr_invoice_number":"FBR-1770805361"}	127.0.0.1	2026-02-01 13:22:41
184	18	7	10	locked	{"fbr_invoice_number":"3620291786117DIACPTQT003255","execution_ms":1418,"mode":"sync"}	10.83.7.79	2026-02-15 14:45:05
8	5	2	\N	locked	{"fbr_invoice_number":"MOCK-FBR-0001"}	127.0.0.1	2026-02-11 11:46:18
10	8	2	4	created	{"buyer_name":"WALK IN CUSTOMER","total_amount":70.8,"items_count":1,"document_type":"Sale Invoice"}	10.83.12.104	2026-02-12 07:06:09
11	8	2	4	submitted	{"mode":"smart","compliance_score":70,"risk_level":"MODERATE"}	10.83.3.120	2026-02-12 07:06:16
12	8	2	\N	retry	{"attempt":1,"failure_type":"payload_error"}	127.0.0.1	2026-02-12 07:06:17
13	8	2	\N	retry	{"attempt":2,"failure_type":"payload_error"}	127.0.0.1	2026-02-12 07:06:47
14	8	2	\N	retry	{"attempt":3,"failure_type":"payload_error"}	127.0.0.1	2026-02-12 07:07:48
15	8	2	\N	fbr_failed	{"error":"App\\\\Jobs\\\\SendInvoiceToFbrJob has been attempted too many times."}	127.0.0.1	2026-02-12 07:09:50
16	8	2	4	edited	{"old":{"buyer_name":"WALK IN CUSTOMER","buyer_ntn":null,"total_amount":"70.80"},"new":{"buyer_name":"WALK IN CUSTOMER","buyer_ntn":null,"total_amount":70.8}}	10.83.0.98	2026-02-12 08:50:20
17	8	2	4	override_submitted	{"mode":"direct_mis","override_reason":"updated and remove error","override_by":"Demo Company Admin"}	10.83.6.125	2026-02-12 08:51:11
18	8	2	\N	locked	{"fbr_invoice_number":"MOCK-FBR-1982"}	127.0.0.1	2026-02-12 08:51:11
19	8	2	\N	locked	{"fbr_invoice_number":"MOCK-FBR-4296"}	127.0.0.1	2026-02-12 08:51:41
20	8	2	\N	locked	{"fbr_invoice_number":"MOCK-FBR-7667"}	127.0.0.1	2026-02-12 08:52:42
21	8	2	\N	fbr_failed	{"error":"SQLSTATE[23502]: Not null violation: 7 ERROR:  null value in column \\"customer_ntn\\" of relation \\"customer_ledgers\\" violates not-null constraint\\nDETAIL:  Failing row contains (3, 2, WALK IN CUSTOMER, null, 8, 70.80, 0.00, 70.80, invoice, Invoice DEMOT-000001 locked, 2026-02-12 08:52:42, 2026-02-12 08:52:42). (Connection: pgsql, Host: helium, Port: 5432, Database: heliumdb, SQL: insert into \\"customer_ledgers\\" (\\"company_id\\", \\"customer_name\\", \\"customer_ntn\\", \\"invoice_id\\", \\"debit\\", \\"credit\\", \\"balance_after\\", \\"type\\", \\"notes\\", \\"updated_at\\", \\"created_at\\") values (2, WALK IN CUSTOMER, ?, 8, 70.80, 0, 70.8, invoice, Invoice DEMOT-000001 locked, 2026-02-12 08:52:42, 2026-02-12 08:52:42) returning \\"id\\")"}	127.0.0.1	2026-02-12 08:52:42
22	8	2	4	retry_submitted	{"retried_by":"Demo Company Admin"}	10.83.12.104	2026-02-12 09:41:50
23	8	2	\N	locked	{"fbr_invoice_number":"MOCK-FBR-6129"}	127.0.0.1	2026-02-12 09:41:50
24	8	2	\N	locked	{"fbr_invoice_number":"MOCK-FBR-2258"}	127.0.0.1	2026-02-12 09:42:20
25	8	2	\N	locked	{"fbr_invoice_number":"MOCK-FBR-1091"}	127.0.0.1	2026-02-12 09:43:21
26	8	2	\N	fbr_failed	{"error":"SQLSTATE[23502]: Not null violation: 7 ERROR:  null value in column \\"customer_ntn\\" of relation \\"customer_ledgers\\" violates not-null constraint\\nDETAIL:  Failing row contains (6, 2, WALK IN CUSTOMER, null, 8, 70.80, 0.00, 70.80, invoice, Invoice DEMOT-000001 locked, 2026-02-12 09:43:21, 2026-02-12 09:43:21). (Connection: pgsql, Host: helium, Port: 5432, Database: heliumdb, SQL: insert into \\"customer_ledgers\\" (\\"company_id\\", \\"customer_name\\", \\"customer_ntn\\", \\"invoice_id\\", \\"debit\\", \\"credit\\", \\"balance_after\\", \\"type\\", \\"notes\\", \\"updated_at\\", \\"created_at\\") values (2, WALK IN CUSTOMER, ?, 8, 70.80, 0, 70.8, invoice, Invoice DEMOT-000001 locked, 2026-02-12 09:43:21, 2026-02-12 09:43:21) returning \\"id\\")"}	127.0.0.1	2026-02-12 09:43:21
27	8	2	4	retry_submitted	{"retried_by":"Demo Company Admin"}	10.83.12.104	2026-02-12 11:47:13
28	8	2	\N	locked	{"fbr_invoice_number":"MOCK-FBR-9205"}	127.0.0.1	2026-02-12 11:47:16
29	8	2	\N	locked	{"fbr_invoice_number":"MOCK-FBR-3752"}	127.0.0.1	2026-02-12 11:47:46
30	8	2	\N	locked	{"fbr_invoice_number":"MOCK-FBR-7214"}	127.0.0.1	2026-02-12 11:48:46
31	8	2	\N	fbr_failed	{"error":"SQLSTATE[23502]: Not null violation: 7 ERROR:  null value in column \\"customer_ntn\\" of relation \\"customer_ledgers\\" violates not-null constraint\\nDETAIL:  Failing row contains (9, 2, WALK IN CUSTOMER, null, 8, 70.80, 0.00, 70.80, invoice, Invoice DEMOT-000001 locked, 2026-02-12 11:48:46, 2026-02-12 11:48:46). (Connection: pgsql, Host: helium, Port: 5432, Database: heliumdb, SQL: insert into \\"customer_ledgers\\" (\\"company_id\\", \\"customer_name\\", \\"customer_ntn\\", \\"invoice_id\\", \\"debit\\", \\"credit\\", \\"balance_after\\", \\"type\\", \\"notes\\", \\"updated_at\\", \\"created_at\\") values (2, WALK IN CUSTOMER, ?, 8, 70.80, 0, 70.8, invoice, Invoice DEMOT-000001 locked, 2026-02-12 11:48:46, 2026-02-12 11:48:46) returning \\"id\\")"}	127.0.0.1	2026-02-12 11:48:46
32	8	2	\N	locked	{"fbr_invoice_number":"MOCK-FBR-4177"}	127.0.0.1	2026-02-12 11:52:40
33	9	7	10	created	{"buyer_name":"walk in customer","total_amount":273.22,"items_count":1,"document_type":"Sale Invoice"}	10.83.3.120	2026-02-13 05:31:22
34	9	7	10	submitted	{"mode":"smart","compliance_score":55,"risk_level":"HIGH"}	10.83.3.120	2026-02-13 05:31:31
35	9	7	\N	locked	{"fbr_invoice_number":"MOCK-FBR-2457"}	127.0.0.1	2026-02-13 05:31:32
36	9	7	\N	retry	{"attempt":1,"failure_type":"token_error"}	127.0.0.1	2026-02-13 05:39:31
37	9	7	\N	retry	{"attempt":2,"failure_type":"token_error"}	127.0.0.1	2026-02-13 05:40:02
38	9	7	\N	fbr_failed	{"attempt":3,"failure_type":"token_error","errors":[]}	127.0.0.1	2026-02-13 05:41:03
39	9	7	10	submitted	{"mode":"smart","compliance_score":55,"risk_level":"HIGH"}	10.83.2.144	2026-02-13 07:35:18
40	9	7	\N	retry	{"attempt":1,"failure_type":"token_error"}	127.0.0.1	2026-02-13 07:35:20
41	9	7	\N	retry	{"attempt":2,"failure_type":"token_error"}	127.0.0.1	2026-02-13 07:35:51
42	9	7	\N	fbr_failed	{"attempt":3,"failure_type":"token_error","errors":[]}	127.0.0.1	2026-02-13 07:36:52
43	9	7	10	resubmit_failed	{"failure_type":"validation_error","errors":["[500] Some thing went wrong. Please try again later."],"environment":"production"}	10.83.2.144	2026-02-13 07:38:44
44	9	7	10	retry_submitted	{"retried_by":"ZIA UR REHMAN"}	10.83.6.125	2026-02-13 08:26:51
45	9	7	\N	retry	{"attempt":1,"failure_type":"token_error"}	127.0.0.1	2026-02-13 08:26:54
46	9	7	\N	retry	{"attempt":2,"failure_type":"token_error"}	127.0.0.1	2026-02-13 08:27:25
47	9	7	\N	fbr_failed	{"attempt":3,"failure_type":"token_error","errors":[]}	127.0.0.1	2026-02-13 08:28:26
48	9	7	10	resubmit_failed	{"failure_type":"validation_error","errors":["[500] Some thing went wrong. Please try again later."],"environment":"production"}	10.83.2.144	2026-02-13 09:22:13
49	9	7	10	resubmit_failed	{"failure_type":"rate_limited","errors":["FBR returned empty response (possible rate limiting). Please wait 2-3 minutes and try again."],"environment":"production"}	10.83.11.158	2026-02-13 09:37:28
50	9	7	10	resubmit_failed	{"failure_type":"rate_limited","errors":["FBR returned empty response (possible rate limiting). Please wait 2-3 minutes and try again."],"environment":"production"}	10.83.11.158	2026-02-13 09:37:37
51	9	7	10	resubmit_failed	{"failure_type":"rate_limited","errors":["FBR returned empty response (possible rate limiting). Please wait 2-3 minutes and try again."],"environment":"production"}	10.83.3.120	2026-02-13 09:40:06
52	9	7	10	edited	{"old":{"buyer_name":"walk in customer","buyer_ntn":null,"total_amount":"273.22"},"new":{"buyer_name":"JAWAD","buyer_ntn":null,"total_amount":273.22}}	10.83.4.235	2026-02-13 09:48:46
53	9	7	10	override_submitted	{"mode":"direct_mis","override_reason":"OK YAR KR DO SUBMIIT","override_by":"ZIA UR REHMAN"}	10.83.4.235	2026-02-13 09:49:14
54	9	7	\N	retry	{"attempt":1,"failure_type":"rate_limited"}	127.0.0.1	2026-02-13 09:49:17
55	9	7	10	resubmit_failed	{"failure_type":"rate_limited","errors":["FBR returned empty response (possible rate limiting). Please wait 2-3 minutes and try again."],"environment":"production"}	10.83.4.235	2026-02-13 09:49:28
56	9	7	\N	retry	{"attempt":2,"failure_type":"validation_error"}	127.0.0.1	2026-02-13 09:49:48
57	9	7	\N	fbr_failed	{"attempt":3,"failure_type":"rate_limited","errors":["FBR returned empty response (possible rate limiting). Please wait 2-3 minutes and try again."]}	127.0.0.1	2026-02-13 09:50:50
58	9	7	10	edited	{"old":{"buyer_name":"JAWAD","buyer_ntn":null,"total_amount":"273.22"},"new":{"buyer_name":"CONFIRM","buyer_ntn":null,"total_amount":273.22}}	10.83.0.98	2026-02-13 09:52:47
59	9	7	10	override_submitted	{"mode":"direct_mis","override_reason":"OK YEH INVOICE TRY KAY LIYE HAI AB","override_by":"ZIA UR REHMAN"}	10.83.0.98	2026-02-13 09:53:03
60	9	7	\N	retry	{"attempt":1,"failure_type":"rate_limited"}	127.0.0.1	2026-02-13 09:53:06
61	9	7	10	resubmit_failed	{"failure_type":"rate_limited","errors":["FBR returned empty response (possible rate limiting). Please wait 2-3 minutes and try again."],"environment":"production"}	10.83.0.98	2026-02-13 09:53:10
62	9	7	\N	retry	{"attempt":2,"failure_type":"rate_limited"}	127.0.0.1	2026-02-13 09:53:37
63	9	7	\N	fbr_failed	{"attempt":3,"failure_type":"rate_limited","errors":["FBR returned empty response (possible rate limiting). Please wait 2-3 minutes and try again."]}	127.0.0.1	2026-02-13 09:54:39
64	9	7	10	edited	{"old":{"buyer_name":"CONFIRM","buyer_ntn":null,"total_amount":"273.22"},"new":{"buyer_name":"NISAR","buyer_ntn":null,"total_amount":273.22}}	10.83.0.98	2026-02-13 09:56:33
65	9	7	10	override_submitted	{"mode":"direct_mis","override_reason":"KR DO SUBMIT","override_by":"ZIA UR REHMAN"}	10.83.0.98	2026-02-13 09:57:10
66	9	7	\N	retry	{"attempt":1,"failure_type":"rate_limited"}	127.0.0.1	2026-02-13 09:57:13
67	9	7	\N	retry	{"attempt":2,"failure_type":"rate_limited"}	127.0.0.1	2026-02-13 09:57:44
68	9	7	10	resubmitted_success	{"fbr_invoice_number":"3620291786117DI1770976705355","environment":"production","resubmitted_by":"ZIA UR REHMAN"}	10.83.0.98	2026-02-13 09:58:25
69	9	7	\N	fbr_failed	{"attempt":3,"failure_type":"rate_limited","errors":["FBR returned empty response (possible rate limiting). Please wait 2-3 minutes and try again."]}	127.0.0.1	2026-02-13 09:58:46
70	9	7	10	retry_submitted	{"retried_by":"ZIA UR REHMAN"}	10.83.0.98	2026-02-13 09:58:55
71	9	7	\N	locked	{"fbr_invoice_number":"3620291786117DI1770976737780"}	127.0.0.1	2026-02-13 09:58:57
72	9	7	10	resubmit_failed	{"failure_type":"validation_error","errors":["[500] Some thing went wrong. Please try again later."],"environment":"production"}	10.83.0.98	2026-02-13 09:59:00
73	10	7	10	created	{"buyer_name":"WALK IN CUSTOMER","total_amount":546.44,"items_count":1,"document_type":"Sale Invoice"}	10.83.1.129	2026-02-14 06:23:03
74	10	7	10	submitted	{"mode":"smart","compliance_score":55,"risk_level":"HIGH"}	10.83.1.129	2026-02-14 06:23:37
75	10	7	\N	pending_verification	{"reason":"FBR response ambiguous","attempt":1,"failure_type":"ambiguous_response","execution_ms":1465}	127.0.0.1	2026-02-14 06:23:38
76	10	7	10	manually_confirmed	{"confirmed_by":"ZIA UR REHMAN","action":"confirmed_on_fbr_portal"}	10.83.13.14	2026-02-14 06:32:14
77	10	7	10	fbr_number_updated	{"old_number":null,"new_number":"3620291786117DIACOLWW080848","updated_by":"ZIA UR REHMAN"}	10.83.7.231	2026-02-14 06:49:27
78	11	7	10	created	{"buyer_name":"walk in cutomer","total_amount":819.66,"items_count":1,"document_type":"Sale Invoice"}	10.83.11.49	2026-02-14 07:46:22
79	11	7	10	submitted	{"mode":"smart","compliance_score":55,"risk_level":"HIGH"}	10.83.7.231	2026-02-14 08:08:53
80	12	7	10	created	{"buyer_name":"walk in cutomer","total_amount":273.22,"items_count":1,"document_type":"Sale Invoice"}	10.83.9.201	2026-02-14 09:54:57
81	12	7	\N	fbr_failed	{"failure_type":"token_error","errors":["{\\n\\t\\t\\t\\t\\t\\t\\t\\t\\t\\t\\t\\"dated\\":\\"2026-02-14 15:19:41\\",\\n\\t\\t\\t\\t\\t\\t\\t\\t\\t\\t\\t\\"validationResponse\\": {\\n\\t\\t\\t\\t\\t\\t\\t\\t\\t\\t\\t\\"statusCode\\" : \\"01\\",\\n\\t\\t\\t\\t\\t\\t\\t\\t\\t\\t\\t\\"status\\": \\"Invalid\\",\\n\\t\\t\\t\\t\\t\\t\\t\\t\\t\\t\\t\\"errorCode\\":\\"0401\\",\\n\\t\\t\\t\\t\\t\\t\\t\\t\\t\\t\\t\\"error\\":\\"Unauthorized access: Provided seller registration number is not 13 digits (CNIC) or 7 digits (NTN) or the authorized token does not exist against seller registration number\\"\\n\\t\\t\\t\\t\\t\\t\\t\\t\\t\\t\\t}"],"execution_ms":1466,"mode":"sync"}	127.0.0.1	2026-02-14 10:20:58
82	11	7	10	edited	{"old":{"buyer_name":"walk in cutomer","buyer_ntn":null,"total_amount":"819.66"},"new":{"buyer_name":"walk in cutomer","buyer_ntn":null,"total_amount":819.66}}	10.83.11.49	2026-02-14 10:25:05
83	11	7	10	submitted	{"mode":"smart","compliance_score":55,"risk_level":"HIGH"}	10.83.4.235	2026-02-14 10:25:15
84	12	7	\N	pending_verification	{"reason":"FBR response ambiguous","execution_ms":1195,"mode":"sync"}	127.0.0.1	2026-02-14 10:25:40
85	11	7	\N	pending_verification	{"reason":"FBR response ambiguous","execution_ms":1152,"mode":"sync"}	127.0.0.1	2026-02-14 10:25:53
86	11	7	10	fbr_number_updated	{"old_number":null,"new_number":"3620291786117DIACOPXI193297","updated_by":"ZIA UR REHMAN"}	10.83.3.32	2026-02-14 12:29:12
87	13	7	10	created	{"buyer_name":"Abrar Ahmad","total_amount":819.66,"items_count":1,"document_type":"Sale Invoice"}	10.83.6.39	2026-02-14 12:50:21
88	13	7	10	submitted	{"mode":"smart","compliance_score":55,"risk_level":"HIGH"}	10.83.1.3	2026-02-14 12:50:25
89	13	7	10	verification_rejected	{"rejected_by":"ZIA UR REHMAN","action":"not_found_on_fbr_portal"}	10.83.11.49	2026-02-14 13:05:54
90	13	7	10	submitted	{"mode":"smart","compliance_score":55,"risk_level":"HIGH"}	10.83.4.6	2026-02-14 13:06:02
91	13	7	10	submitted	{"mode":"smart","compliance_score":55,"risk_level":"HIGH"}	10.83.4.6	2026-02-14 13:08:26
92	13	7	10	submitted	{"mode":"smart","compliance_score":55,"risk_level":"HIGH"}	10.83.4.6	2026-02-14 13:46:34
93	14	7	10	created	{"buyer_name":"rayan","total_amount":52.5,"items_count":1,"document_type":"Sale Invoice"}	10.83.4.6	2026-02-14 13:48:07
94	14	7	10	submitted	{"mode":"smart","compliance_score":55,"risk_level":"HIGH"}	10.83.4.6	2026-02-14 13:48:12
95	14	7	10	submitted	{"mode":"smart","compliance_score":55,"risk_level":"HIGH"}	10.83.4.6	2026-02-14 13:52:24
96	14	7	10	submitted	{"mode":"smart","compliance_score":55,"risk_level":"HIGH"}	10.83.4.6	2026-02-14 13:55:54
97	14	7	10	locked	{"fbr_invoice_number":"3620291786117DIACOSDC566199","execution_ms":1503,"mode":"sync"}	10.83.4.6	2026-02-14 13:55:56
98	13	7	10	submitted	{"mode":"smart","compliance_score":55,"risk_level":"HIGH"}	10.83.13.14	2026-02-14 13:56:55
99	13	7	10	pending_verification	{"reason":"FBR response ambiguous","execution_ms":1419,"mode":"sync"}	10.83.13.14	2026-02-14 13:56:57
100	13	7	10	submitted	{"mode":"smart","compliance_score":55,"risk_level":"HIGH"}	10.83.13.14	2026-02-14 13:57:02
101	13	7	10	pending_verification	{"reason":"FBR response ambiguous","execution_ms":1500,"mode":"sync"}	10.83.13.14	2026-02-14 13:57:03
102	13	7	10	submitted	{"mode":"smart","compliance_score":55,"risk_level":"HIGH"}	10.83.4.6	2026-02-14 13:57:11
103	13	7	10	pending_verification	{"reason":"FBR response ambiguous","execution_ms":1124,"mode":"sync"}	10.83.4.6	2026-02-14 13:57:12
104	13	7	10	submitted	{"mode":"smart","compliance_score":55,"risk_level":"HIGH"}	10.83.4.6	2026-02-14 13:59:41
105	13	7	10	pending_verification	{"reason":"FBR response ambiguous","execution_ms":1473,"mode":"sync"}	10.83.4.6	2026-02-14 13:59:43
106	13	7	10	submitted	{"mode":"smart","compliance_score":55,"risk_level":"HIGH"}	10.83.4.6	2026-02-14 13:59:53
107	13	7	10	locked	{"fbr_invoice_number":"3620291786117DIACOSGL329267","execution_ms":1140,"mode":"sync"}	10.83.4.6	2026-02-14 13:59:54
185	19	7	10	created	{"buyer_name":"Sajjad","total_amount":819.66,"items_count":1,"document_type":"Sale Invoice"}	10.83.13.14	2026-02-15 14:47:19
186	19	7	10	submitted	{"mode":"smart","compliance_score":55,"risk_level":"HIGH"}	10.83.12.54	2026-02-15 14:47:26
187	19	7	10	fbr_failed	{"failure_type":"validation_error","errors":["Item 1: [0102] Provided sales tax amount does not match the calculated sales tax amount in case of 3rd schedule goods. Please ensure that the Fixed\\/Notified Value or Retail Price is used to calculated the Sales Tax Amount for the provided rate."],"execution_ms":1412,"mode":"sync"}	10.83.12.54	2026-02-15 14:47:27
188	19	7	10	submitted	{"mode":"smart","compliance_score":55,"risk_level":"HIGH"}	10.83.6.62	2026-02-15 14:49:20
189	19	7	10	fbr_failed	{"failure_type":"validation_error","errors":["Item 1: [0102] Provided sales tax amount does not match the calculated sales tax amount in case of 3rd schedule goods. Please ensure that the Fixed\\/Notified Value or Retail Price is used to calculated the Sales Tax Amount for the provided rate."],"execution_ms":1204,"mode":"sync"}	10.83.6.62	2026-02-15 14:49:21
190	21	7	10	submitted	{"mode":"smart","compliance_score":55,"risk_level":"HIGH"}	10.83.11.104	2026-02-15 17:44:52
191	21	7	10	locked	{"fbr_invoice_number":"3620291786117DIACPWRK747110","execution_ms":1488,"mode":"sync"}	10.83.11.104	2026-02-15 17:44:54
192	19	7	10	submitted	{"mode":"smart","compliance_score":55,"risk_level":"HIGH"}	10.83.9.30	2026-02-15 17:45:06
193	19	7	10	fbr_failed	{"error":"FBR submission blocked: previous success in fbr_logs. Invoice #19","execution_ms":2,"mode":"sync"}	10.83.9.30	2026-02-15 17:45:06
194	19	7	10	edited	{"old":{"buyer_name":"Sajjad","buyer_ntn":null,"total_amount":"819.66"},"new":{"buyer_name":"Sajjad","buyer_ntn":null,"total_amount":1079.87}}	10.83.3.32	2026-02-15 18:26:20
195	19	7	10	submitted	{"mode":"smart","compliance_score":55,"risk_level":"HIGH"}	10.83.3.32	2026-02-15 18:26:25
196	19	7	10	fbr_failed	{"error":"FBR submission blocked: previous success in fbr_logs. Invoice #19","execution_ms":13,"mode":"sync"}	10.83.3.32	2026-02-15 18:26:25
197	19	7	10	edited	{"old":{"buyer_name":"Sajjad","buyer_ntn":null,"total_amount":"1079.87"},"new":{"buyer_name":"Sajjad","buyer_ntn":null,"total_amount":546.44}}	10.83.3.32	2026-02-15 18:27:21
198	19	7	10	submitted	{"mode":"smart","compliance_score":55,"risk_level":"HIGH"}	10.83.3.32	2026-02-15 18:27:27
199	19	7	10	fbr_failed	{"error":"FBR submission blocked: previous success in fbr_logs. Invoice #19","execution_ms":2,"mode":"sync"}	10.83.3.32	2026-02-15 18:27:27
200	26	7	10	created	{"buyer_name":"walk in cutomer","total_amount":2732.2,"items_count":1,"document_type":"Sale Invoice"}	10.83.8.57	2026-02-16 06:01:52
201	26	7	10	submitted	{"mode":"smart","compliance_score":55,"risk_level":"HIGH"}	10.83.3.32	2026-02-16 06:01:56
202	26	7	10	locked	{"fbr_invoice_number":"3620291786117DIACQLAN954426","execution_ms":1420,"mode":"sync"}	10.83.3.32	2026-02-16 06:01:58
203	27	7	10	created	{"buyer_name":"MALIK FAWAD","total_amount":1366.1,"items_count":1,"document_type":"Sale Invoice"}	10.83.6.78	2026-02-16 12:54:54
204	27	7	10	submitted	{"mode":"smart","compliance_score":55,"risk_level":"HIGH"}	10.83.9.39	2026-02-16 12:55:12
205	27	7	10	locked	{"fbr_invoice_number":"3620291786117DIACQRBD942496","execution_ms":1539,"mode":"sync"}	10.83.9.39	2026-02-16 12:55:14
206	28	7	10	created	{"buyer_name":"walk in cutomer","total_amount":1639.32,"items_count":1,"document_type":"Sale Invoice"}	10.83.3.32	2026-02-17 08:56:54
207	28	7	10	submitted	{"mode":"smart","compliance_score":55,"risk_level":"HIGH"}	10.83.7.125	2026-02-17 08:57:13
208	28	7	10	locked	{"fbr_invoice_number":"3620291786117DIACRNFN740406","execution_ms":1458,"mode":"sync"}	10.83.7.125	2026-02-17 08:57:15
212	30	7	10	created	{"buyer_name":"walk in cutomer","total_amount":273.22,"items_count":1,"document_type":"Sale Invoice"}	10.83.8.57	2026-02-17 09:36:40
213	30	7	10	submitted	{"mode":"smart","compliance_score":55,"risk_level":"HIGH"}	10.83.4.76	2026-02-17 09:36:46
214	30	7	10	locked	{"fbr_invoice_number":"3620291786117DIACROKV587270","execution_ms":1420,"mode":"sync"}	10.83.4.76	2026-02-17 09:36:48
\.


--
-- Data for Name: invoice_items; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.invoice_items (id, invoice_id, hs_code, description, quantity, price, tax, created_at, updated_at, schedule_type, pct_code, tax_rate, sro_schedule_no, serial_no, mrp, default_uom, sale_type, st_withheld_at_source, petroleum_levy, further_tax) FROM stdin;
1	1	8471.3010	Professional Tax Consultation	1.00	12000.00	3000.00	2026-02-11 10:22:41	2026-02-11 10:22:41	standard	\N	18.00	\N	\N	\N	Numbers, pieces, units	Goods at standard rate (default)	f	\N	0.00
2	1	9988.7766	Administrative Service Fee	1.00	2000.00	340.00	2026-02-11 10:22:41	2026-02-11 10:22:41	standard	\N	18.00	\N	\N	\N	Numbers, pieces, units	Goods at standard rate (default)	f	\N	0.00
5	3	8471.3010	Professional Tax Consultation	1.00	36000.00	9000.00	2026-02-11 10:22:41	2026-02-11 10:22:41	standard	\N	18.00	\N	\N	\N	Numbers, pieces, units	Goods at standard rate (default)	f	\N	0.00
6	3	9988.7766	Administrative Service Fee	1.00	2000.00	340.00	2026-02-11 10:22:41	2026-02-11 10:22:41	standard	\N	18.00	\N	\N	\N	Numbers, pieces, units	Goods at standard rate (default)	f	\N	0.00
8	5	25232900	Cement Bag	20.00	1250.00	4500.00	2026-02-11 11:46:17	2026-02-11 11:46:17	standard	\N	18.00	\N	\N	\N	Numbers, pieces, units	Goods at standard rate (default)	f	\N	0.00
12	8	2202.1010	BEVERAGES	1.00	60.00	10.80	2026-02-12 08:50:20	2026-02-12 08:50:20	3rd_schedule	\N	18.00	\N	\N	60.00	Liters	Goods under 3rd Schedule	f	\N	0.00
16	9	3105.3000	Dap	1.00	260.21	13.01	2026-02-13 09:56:33	2026-02-13 09:56:33	3rd_schedule	\N	5.00	3rd Schedule goods	51	260.21	Kilograms	3rd Schedule Goods	f	\N	0.00
17	10	3105.3000	Dap	2.00	260.21	26.02	2026-02-14 06:23:03	2026-02-14 06:23:03	3rd_schedule	\N	5.00	3rd Schedule Goods	51	260.21	Kilograms	3rd Schedule Goods	f	\N	0.00
19	12	3105.3000	Dap	1.00	260.21	13.01	2026-02-14 09:54:57	2026-02-14 09:54:57	3rd_schedule	\N	5.00	3rd Schedule Goods	51	260.21	Kilograms	3rd Schedule Goods	f	\N	0.00
20	11	3105.3000	Dap	3.00	260.21	39.03	2026-02-14 10:25:05	2026-02-14 10:25:05	3rd_schedule	\N	5.00	3rd Schedule Goods	51	260.21	Kilograms	3rd Schedule Goods	f	\N	0.00
21	13	3105.3000	Dap	3.00	260.21	39.03	2026-02-14 12:50:21	2026-02-14 12:50:21	3rd_schedule	\N	5.00	3rd Schedule goods	51	260.21	Kilograms	3rd Schedule Goods	f	\N	0.00
22	14	3105.3000	Dap	1.00	50.00	2.50	2026-02-14 13:48:07	2026-02-14 13:48:07	3rd_schedule	\N	5.00	3rd Schedule goods	51	50.00	Kilograms	3rd Schedule Goods	f	\N	0.00
32	18	3105.3000	Dap	1.00	260.21	13.01	2026-02-15 14:29:21	2026-02-15 14:29:21	3rd_schedule	\N	5.00	3rd Schedule goods	51	260.21	Kilograms	3rd Schedule Goods	f	\N	0.00
43	28	3105.3000	Dap	6.00	260.21	78.06	2026-02-17 08:56:54	2026-02-17 08:56:54	3rd_schedule	\N	5.00	3rd Schedule goods	51	260.21	Kilograms	3rd Schedule Goods	f	\N	0.00
34	21	3105.3000	Dap	1.00	1040.84	39.03	2026-02-15 15:21:16	2026-02-15 15:21:16	3rd_schedule	\N	5.00	3rd Schedule goods	51	1040.84	Kilograms	3rd Schedule Goods	f	\N	0.00
35	22	3105.3000	Fertilizer - DAP (3105.3000)	50.00	260.21	650.53	2026-02-15 15:52:13	2026-02-15 15:52:13	3rd_schedule	\N	5.00	3rd Schedule goods	51	260.21	KG	3rd Schedule Goods	f	\N	0.00
36	23	3105.3000	Fertilizer - DAP (3105.3000)	100.00	260.21	1301.05	2026-02-15 15:52:14	2026-02-15 15:52:14	3rd_schedule	\N	5.00	3rd Schedule goods	51	260.21	KG	3rd Schedule Goods	f	\N	0.00
37	24	3105.3000	Fertilizer - DAP (3105.3000)	150.00	260.21	1951.58	2026-02-15 15:52:14	2026-02-15 15:52:14	3rd_schedule	\N	5.00	3rd Schedule goods	51	260.21	KG	3rd Schedule Goods	f	\N	0.00
38	25	3105.3000	Fertilizer - DAP (3105.3000)	200.00	260.21	2602.10	2026-02-15 15:52:14	2026-02-15 15:52:14	3rd_schedule	\N	5.00	3rd Schedule goods	51	260.21	KG	3rd Schedule Goods	f	\N	0.00
40	19	3105.3000	Dap	2.00	260.21	26.02	2026-02-15 18:27:21	2026-02-15 18:27:21	3rd_schedule	\N	5.00	3rd Schedule goods	51	260.21	Kilograms	3rd Schedule Goods	f	\N	0.00
41	26	3105.3000	Dap	10.00	260.21	130.10	2026-02-16 06:01:52	2026-02-16 06:01:52	3rd_schedule	\N	5.00	3rd Schedule goods	51	260.21	Kilograms	3rd Schedule Goods	f	\N	0.00
42	27	3105.3000	Dap	5.00	260.21	65.05	2026-02-16 12:54:54	2026-02-16 12:54:54	3rd_schedule	\N	5.00	3rd Schedule goods	51	260.21	Kilograms	3rd Schedule Goods	f	\N	0.00
45	30	3105.3000	Dap	1.00	260.21	13.01	2026-02-17 09:36:40	2026-02-17 09:36:40	3rd_schedule	\N	5.00	3rd Schedule goods	51	260.21	Kilograms	3rd Schedule Goods	f	\N	10.41
\.


--
-- Data for Name: invoices; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.invoices (id, company_id, invoice_number, status, buyer_name, buyer_ntn, total_amount, created_at, updated_at, integrity_hash, override_reason, override_by, submission_mode, fbr_invoice_id, qr_data, share_uuid, branch_id, internal_invoice_number, fbr_invoice_number, fbr_submission_date, document_type, reference_invoice_number, buyer_registration_type, supplier_province, destination_province, total_value_excluding_st, total_sales_tax, wht_rate, wht_amount, net_receivable, fbr_status, invoice_date, buyer_cnic, buyer_address, submitted_at, fbr_submission_hash, is_fbr_processing, wht_locked) FROM stdin;
1	1	INV-20260211-001	draft	ABC Traders	7654321-0	15000.00	2026-02-10 10:22:41	2026-02-11 11:46:21	\N	\N	\N	\N	\N	\N	5377d464-c29e-43c5-b5ff-f22e9000caa1	\N	INV-20260211-001	\N	\N	Sale Invoice	\N	Registered	\N	\N	0.00	0.00	0.0000	0.00	0.00	\N	\N	\N	\N	\N	\N	f	f
5	2	DEMO-INV-002	locked	Lahore Builders Pvt Ltd	3344556-7	29500.00	2026-02-11 11:46:17	2026-02-11 11:46:17	9bb396b8c17e5099f7e11db51eab600d90f6f913048a345ce5265ce522ebf199	\N	\N	smart	MOCK-FBR-0001	{"ntn":"9876543-2","invoice_number":"DEMO-INV-002","fbr_invoice_id":"MOCK-FBR-0001","date":"2026-02-09","total":29500}	023c5511-ca1e-47cd-8255-c59349ff8443	\N	DEMO-INV-002	\N	\N	Sale Invoice	\N	Registered	\N	\N	0.00	0.00	0.0000	0.00	0.00	\N	\N	\N	\N	\N	\N	f	f
23	7	3620291786117DI1771170733992	locked	Walk-in Customer	\N	27322.05	2026-02-15 15:52:14	2026-02-15 15:53:09	\N	\N	\N	\N	3620291786117DIACPUZZ535641	\N	775844bc-f92b-4ade-95e8-41308f398fec	\N	3620291786117DI1771170733992	3620291786117DIACPUZZ535641	2026-02-15 15:53:09	Sale Invoice	\N	Unregistered	Punjab	Punjab	26021.00	1301.05	0.0000	0.00	27322.05	production	2026-02-15	\N	Lahore, Pakistan	\N	9f40bbafaf51351c5ea00b949fe936a1449d0d5110967b88e143c4642e3aecf2	f	f
26	7	3620291786117DI1771221712284	locked	walk in cutomer	\N	2732.20	2026-02-16 06:01:52	2026-02-16 06:01:58	67bbede58414cc2883df599bc80722526a2dddc169c6ab22573920bbdfc855be	\N	\N	smart	3620291786117DIACQLAN954426	{"sellerNTNCNIC":"3620291786117","fbr_invoice_number":"3620291786117DIACQLAN954426","invoiceDate":"2026-02-16","totalValues":"2732.20"}	f0b9de3c-f6e4-4661-901c-55a50c5b246f	\N	3620291786117DI1771221712284	3620291786117DIACQLAN954426	2026-02-16 06:01:58	Sale Invoice	\N	Unregistered	\N	Punjab	2602.10	130.10	0.0000	0.00	2732.20	production	2026-02-16	\N	Kahror pakka	2026-02-16 06:01:56	ab50c147282ec58716df81b970e600634dfed00a7e457058d916b0f2c494df87	f	f
3	1	INV-20260211-003	locked	Global Logistics	9988776-6	45000.00	2026-02-01 10:22:41	2026-02-11 11:46:21	b91420d356346db486f6edb95c9814a1aa661ef547e8caa604b96485a449e6d6	\N	\N	\N	\N	\N	da8a6f15-2f79-45bb-a9db-db8b163fa7b5	\N	INV-20260211-003	\N	\N	Sale Invoice	\N	Registered	\N	\N	0.00	0.00	0.0000	0.00	0.00	\N	\N	\N	\N	\N	\N	f	f
8	2	DEMOT-000001	locked	WALK IN CUSTOMER	\N	70.80	2026-02-12 07:06:09	2026-02-12 11:52:40	818179ad1b9bfc2422885dbaee87c2b1617febc5e83661e46bef685f59e5e0e5	updated and remove error	4	direct_mis	MOCK-FBR-4177	{"ntn":"9876543-2","invoice_number":"DEMOT-000001","fbr_invoice_id":"MOCK-FBR-4177","date":"2026-02-12","total":"70.80"}	29cc3113-aba8-40fd-9209-b07f91176190	\N	DEMOT-000001	MOCK-FBR-4177	2026-02-12 11:52:40	Sale Invoice	\N	Unregistered	\N	Punjab	60.00	10.80	0.0000	0.00	70.80	\N	2026-02-12	\N	LODHRAN	\N	\N	f	f
18	7	3620291786117DI1771165737745	locked	NISAR		273.22	2026-02-15 14:29:10	2026-02-15 14:45:05	29b81c685b6abeb0e091ecc316fefd387af5bd2699fc70dcdabdcadb44abb511	\N	\N	smart	3620291786117DIACPTQT003255	{"sellerNTNCNIC":"3620291786117","fbr_invoice_number":"3620291786117DIACPTQT003255","invoiceDate":"2026-02-15","totalValues":"273.22"}	\N	\N	\N	3620291786117DIACPTQT003255	2026-02-15 14:45:05	Sale Invoice	\N	Unregistered		Punjab	0.00	0.00	0.0000	0.00	0.00	production	2026-02-15		kahror pakka	2026-02-15 14:45:04	\N	f	f
22	7	3620291786117DI1771170733600	locked	Walk-in Customer	\N	13661.03	2026-02-15 15:52:13	2026-02-15 15:52:47	\N	\N	\N	\N	3620291786117DIACPUAT065851	\N	e995df9d-5732-4321-8d76-4da77e29d366	\N	3620291786117DI1771170733600	3620291786117DIACPUAT065851	2026-02-15 15:52:47	Sale Invoice	\N	Unregistered	Punjab	Punjab	13010.50	650.53	0.0000	0.00	13661.03	production	2026-02-15	\N	Lahore, Pakistan	\N	a3ea483084e43676d080fa5a7c30bcd94d141275b9063546c288b55f44de7f0c	f	f
24	7	3620291786117DI1771170734016	locked	Walk-in Customer	\N	40983.08	2026-02-15 15:52:14	2026-02-15 15:53:29	\N	\N	\N	\N	3620291786117DIACPUZI011199	\N	140c6c14-8b1c-4234-9558-b215a65e2c87	\N	3620291786117DI1771170734016	3620291786117DIACPUZI011199	2026-02-15 15:53:29	Sale Invoice	\N	Unregistered	Punjab	Punjab	39031.50	1951.58	0.0000	0.00	40983.08	production	2026-02-15	\N	Lahore, Pakistan	\N	67d133ed30399b2d501148040a8f6216de23cb498a458808e4f3598f9f20d794	f	f
10	7	3620291786117DI1771050183903	locked	WALK IN CUSTOMER	\N	546.44	2026-02-14 06:23:03	2026-02-14 06:49:27	7ee6d94838a06ae3cca4830fe5d8fccd345e025ccef9749081360fe237692754	\N	\N	smart	3620291786117DIACOLWW080848	{"sellerNTNCNIC":"3620291786117","fbr_invoice_number":"3620291786117DIACOLWW080848","invoiceDate":"2026-02-14","totalValues":"546.44"}	caf6615a-e82d-4237-a77b-f022ec987323	\N	3620291786117DI1771050183903	3620291786117DIACOLWW080848	2026-02-14 06:32:14	Sale Invoice	\N	Unregistered	\N	Punjab	520.42	26.02	0.0000	0.00	546.44	production	2026-02-14	\N	kahror pakka	2026-02-14 06:23:37	\N	f	f
9	7	3620291786117DI1770965899484	locked	NISAR	\N	273.22	2026-02-13 05:31:22	2026-02-13 09:58:57	a7c879da049b5dedb05aa3100ef7180b977524d27269c7e15b92d0e62298b977	KR DO SUBMIT	10	direct_mis	3620291786117DI1770976737780	{"ntn":"3620291786117","invoice_number":"3620291786117DI1770965899484","fbr_invoice_id":"3620291786117DI1770976737780","date":"2026-02-13","total":"273.22"}	fcaa700b-8598-4b9d-a1c2-49c23c8db1d8	\N	3620291786117DI1770965899484	3620291786117DIACNODA872455	2026-02-13 09:58:57	Sale Invoice	\N	Unregistered	\N	Punjab	260.21	13.01	0.0000	0.00	273.22	production	2026-02-13	\N	kahror pakka	\N	\N	f	f
14	7	3620291786117DI1771076887931	locked	rayan	\N	52.50	2026-02-14 13:48:07	2026-02-14 13:55:56	9544c6f829ad82acac7e3636b0e1d5f04043ae0e27cc80fc3a8fae1902c786b3	\N	\N	smart	3620291786117DIACOSDC566199	{"sellerNTNCNIC":"3620291786117","fbr_invoice_number":"3620291786117DIACOSDC566199","invoiceDate":"2026-02-14","totalValues":"52.50"}	10b7fa4f-c312-4f07-98e4-4d867b0ba5b9	\N	3620291786117DI1771076887931	3620291786117DIACOSDC566199	2026-02-14 13:55:56	Sale Invoice	\N	Unregistered	\N	Punjab	50.00	2.50	0.0000	0.00	52.50	production	2026-02-14	\N	lodhran	2026-02-14 13:55:54	\N	f	f
25	7	3620291786117DI1771170734028	locked	Walk-in Customer	\N	54644.10	2026-02-15 15:52:14	2026-02-15 15:53:52	\N	\N	\N	\N	3620291786117DIACPUAI630424	\N	cb1bc003-f5c5-4b15-a1fb-9d7ad00c2a35	\N	3620291786117DI1771170734028	3620291786117DIACPUAI630424	2026-02-15 15:53:52	Sale Invoice	\N	Unregistered	Punjab	Punjab	52042.00	2602.10	0.0000	0.00	54644.10	production	2026-02-15	\N	Lahore, Pakistan	\N	955eaf318c88fe5cf1ad5f412d530384021d87a719e7dbb2602872056352404b	f	f
27	7	3620291786117DI1771246493957	locked	MALIK FAWAD	\N	1366.10	2026-02-16 12:54:54	2026-02-16 12:55:14	42dd40ca567cd422c9e0195a18418acae2f8290439c3b937742a3498d9e36059	\N	\N	smart	3620291786117DIACQRBD942496	{"sellerNTNCNIC":"3620291786117","fbr_invoice_number":"3620291786117DIACQRBD942496","invoiceDate":"2026-02-16","totalValues":"1366.10"}	aafe84b4-56b0-4d13-897d-aa9edb0cfd44	\N	3620291786117DI1771246493957	3620291786117DIACQRBD942496	2026-02-16 12:55:14	Sale Invoice	\N	Unregistered	\N	Punjab	1301.05	65.05	0.0000	0.00	1366.10	production	2026-02-16	\N	THADDA THAHEEM LODHRAN	2026-02-16 12:55:12	fbb9685dd31407d7bf444040b69903ad9a9a13fd45aa2c34a7025d74453fda2b	f	f
28	7	3620291786117DI1771318614634	locked	walk in cutomer	\N	1639.32	2026-02-17 08:56:54	2026-02-17 08:57:15	c3520deac1edfd9705ee267fc28a3b93422c8fff1f0c7a3f5436e6425eb7b580	\N	\N	smart	3620291786117DIACRNFN740406	{"sellerNTNCNIC":"3620291786117","fbr_invoice_number":"3620291786117DIACRNFN740406","invoiceDate":"2026-02-17","totalValues":"1639.32"}	20504cae-ff33-4955-8c7d-1a8f43d3706e	\N	3620291786117DI1771318614634	3620291786117DIACRNFN740406	2026-02-17 08:57:15	Sale Invoice	\N	Unregistered	\N	Punjab	1561.26	78.06	0.0000	0.00	1639.32	production	2026-02-17	\N	Kahror pakka	2026-02-17 08:57:13	9eb286ebcda068e8f2bee128ee4b0e00c6e10ac1be93d9474da2e2d0c83a4969	f	f
13	7	3620291786117DI1771077549919	locked	Abrar Ahmad	\N	819.66	2026-02-14 12:50:21	2026-02-14 13:59:54	6e7a21dc65ce13590821b178c5ce98ad36159793f3a14fbd175c2980811e5066	\N	\N	smart	3620291786117DIACOSGL329267	{"sellerNTNCNIC":"3620291786117","fbr_invoice_number":"3620291786117DIACOSGL329267","invoiceDate":"2026-02-14","totalValues":"819.66"}	d5c296ff-94f8-4b29-8306-16c6c981f18f	\N	3620291786117DI1771077549919	3620291786117DIACOSGL329267	2026-02-14 13:59:54	Sale Invoice	\N	Unregistered	\N	Punjab	780.63	39.03	0.0000	0.00	819.66	production	2026-02-14	\N	lodhran	2026-02-14 13:59:53	\N	f	f
11	7	36381144DI1771055182422	locked	walk in cutomer	\N	819.66	2026-02-14 07:46:22	2026-02-14 12:29:12	5182551a39197f0ff17b9ff83dc4a8795df40b11f3b93748b27395d0b67c819c	\N	\N	smart	3620291786117DIACOPXI193297	{"sellerNTNCNIC":"36381144","fbr_invoice_number":"3620291786117DIACOPXI193297","invoiceDate":"2026-02-14","totalValues":"819.66"}	32a8bf71-83b0-494a-b65f-320e4a8c3cfc	\N	36381144DI1771055182422	3620291786117DIACOPXI193297	2026-02-14 12:29:12	Sale Invoice	\N	Unregistered	\N	Punjab	780.63	39.03	0.0000	0.00	819.66	production	2026-02-14	\N	Kahror pakka	2026-02-14 10:25:52	\N	f	f
12	7	36381144DI1771062897784	locked	walk in cutomer	\N	273.22	2026-02-14 09:54:57	2026-02-14 12:36:19	c0ae02cfc4f81bcec11d512386fb9ad2c3443c8bcc88cc02047936c0258c5cf6	\N	\N	\N	3620291786117DIACOPYY771917	{"sellerNTNCNIC":"3620291786117","fbr_invoice_number":"3620291786117DIACOPYY771917","invoiceDate":"2026-02-14","totalValues":"273.22"}	e6f80c77-56e3-4153-b1a6-a8f58ccda916	\N	36381144DI1771062897784	3620291786117DIACOPYY771917	2026-02-14 12:36:19	Sale Invoice	\N	Unregistered	\N	Punjab	260.21	13.01	0.0000	0.00	273.22	production	2026-02-14	\N	Kahror pakka	2026-02-14 10:25:39	\N	f	f
21	7	3620291786117DI1771168876103	locked	Sajjad	\N	819.66	2026-02-15 15:21:16	2026-02-15 17:44:54	71c7b1a1125e5fd14e93917a0c6fe2085a3ba127eb68cbd75b56ee518ec231a9	\N	\N	smart	3620291786117DIACPWRK747110	{"sellerNTNCNIC":"3620291786117","fbr_invoice_number":"3620291786117DIACPWRK747110","invoiceDate":"2026-02-15","totalValues":"819.66"}	d45ac970-b00b-4651-a1ca-f59ef4ce5a69	\N	3620291786117DI1771168876103	3620291786117DIACPWRK747110	2026-02-15 17:44:54	Sale Invoice	\N	Unregistered	\N	Punjab	780.63	39.03	0.0000	0.00	819.66	production	2026-02-15	\N	Lodhran	2026-02-15 17:44:52	9c9e087794d22ba3c77fe6ede5e083dace638132fcb85615fd770f8f85ca4214	f	f
19	7	3620291786117DI1771169651336	locked	Sajjad	\N	546.44	2026-02-15 14:47:19	2026-02-15 18:31:39	\N	\N	\N	smart	\N	\N	d652179f-9495-43b6-8746-2d38c080853b	\N	3620291786117DI1771169651336	3620291786117DIACPUFA742661	\N	Sale Invoice	\N	Unregistered	\N	Punjab	520.42	26.02	0.0000	0.00	546.44	production	2026-02-15	\N	Lodhran	2026-02-15 18:27:27	\N	f	f
30	7	3620291786117DI1771321000267	locked	walk in cutomer	\N	273.22	2026-02-17 09:36:40	2026-02-17 10:30:13	5d1a35e810b8db3a74af2804c58a151ab0e5ea245113c146edbaf08078d6689c	\N	\N	smart	3620291786117DIACROKV587270	{"sellerNTNCNIC":"3620291786117","fbr_invoice_number":"3620291786117DIACROKV587270","invoiceDate":"2026-02-17","totalValues":"273.22"}	37cef486-1b70-4be0-be7a-fad7dbaa7f9e	\N	3620291786117DI1771321000267	3620291786117DIACROKV587270	2026-02-17 09:36:48	Sale Invoice	\N	Unregistered	\N	Punjab	260.21	13.01	0.0000	0.00	273.22	production	2026-02-17	\N	Kahror pakka	2026-02-17 09:36:46	7948e5d3214eb36c5386a21de6590a77285000decfc6b95caf121405050c9ef2	f	t
\.


--
-- Data for Name: job_batches; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.job_batches (id, name, total_jobs, pending_jobs, failed_jobs, failed_job_ids, options, cancelled_at, created_at, finished_at) FROM stdin;
\.


--
-- Data for Name: jobs; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.jobs (id, queue, payload, attempts, reserved_at, available_at, created_at) FROM stdin;
129	default	{"uuid":"9bfcfcc6-d844-4ef0-b319-aaf6370a996c","displayName":"App\\\\Jobs\\\\ComplianceScoringJob","job":"Illuminate\\\\Queue\\\\CallQueuedHandler@call","maxTries":2,"maxExceptions":null,"failOnTimeout":false,"backoff":"10,30","timeout":null,"retryUntil":null,"data":{"commandName":"App\\\\Jobs\\\\ComplianceScoringJob","command":"O:29:\\"App\\\\Jobs\\\\ComplianceScoringJob\\":1:{s:9:\\"invoiceId\\";i:28;}","batchId":null},"createdAt":1771318614,"delay":null}	0	\N	1771318614	1771318614
130	default	{"uuid":"9a8a1ddb-dd64-41eb-a10e-ea9477fee76f","displayName":"App\\\\Jobs\\\\IntelligenceProcessingJob","job":"Illuminate\\\\Queue\\\\CallQueuedHandler@call","maxTries":2,"maxExceptions":null,"failOnTimeout":false,"backoff":"10,30","timeout":null,"retryUntil":null,"data":{"commandName":"App\\\\Jobs\\\\IntelligenceProcessingJob","command":"O:34:\\"App\\\\Jobs\\\\IntelligenceProcessingJob\\":2:{s:9:\\"invoiceId\\";i:28;s:12:\\"fullAnalysis\\";b:0;}","batchId":null},"createdAt":1771318633,"delay":null}	0	\N	1771318633	1771318633
131	default	{"uuid":"18a7021f-4bf5-45c0-a710-8c98516c7d7a","displayName":"App\\\\Jobs\\\\ComplianceScoringJob","job":"Illuminate\\\\Queue\\\\CallQueuedHandler@call","maxTries":2,"maxExceptions":null,"failOnTimeout":false,"backoff":"10,30","timeout":null,"retryUntil":null,"data":{"commandName":"App\\\\Jobs\\\\ComplianceScoringJob","command":"O:29:\\"App\\\\Jobs\\\\ComplianceScoringJob\\":1:{s:9:\\"invoiceId\\";i:29;}","batchId":null},"createdAt":1771320390,"delay":null}	0	\N	1771320390	1771320390
132	default	{"uuid":"a443e0b5-1a25-4fc6-aca3-cbb485b43165","displayName":"App\\\\Jobs\\\\IntelligenceProcessingJob","job":"Illuminate\\\\Queue\\\\CallQueuedHandler@call","maxTries":2,"maxExceptions":null,"failOnTimeout":false,"backoff":"10,30","timeout":null,"retryUntil":null,"data":{"commandName":"App\\\\Jobs\\\\IntelligenceProcessingJob","command":"O:34:\\"App\\\\Jobs\\\\IntelligenceProcessingJob\\":2:{s:9:\\"invoiceId\\";i:29;s:12:\\"fullAnalysis\\";b:0;}","batchId":null},"createdAt":1771320441,"delay":null}	0	\N	1771320441	1771320441
133	default	{"uuid":"a73280ca-4f02-4e04-b15d-e15be142a0d4","displayName":"App\\\\Jobs\\\\ComplianceScoringJob","job":"Illuminate\\\\Queue\\\\CallQueuedHandler@call","maxTries":2,"maxExceptions":null,"failOnTimeout":false,"backoff":"10,30","timeout":null,"retryUntil":null,"data":{"commandName":"App\\\\Jobs\\\\ComplianceScoringJob","command":"O:29:\\"App\\\\Jobs\\\\ComplianceScoringJob\\":1:{s:9:\\"invoiceId\\";i:30;}","batchId":null},"createdAt":1771321000,"delay":null}	0	\N	1771321000	1771321000
134	default	{"uuid":"8be0b6ff-8750-42e7-8fa0-263d9c0d9e4d","displayName":"App\\\\Jobs\\\\IntelligenceProcessingJob","job":"Illuminate\\\\Queue\\\\CallQueuedHandler@call","maxTries":2,"maxExceptions":null,"failOnTimeout":false,"backoff":"10,30","timeout":null,"retryUntil":null,"data":{"commandName":"App\\\\Jobs\\\\IntelligenceProcessingJob","command":"O:34:\\"App\\\\Jobs\\\\IntelligenceProcessingJob\\":2:{s:9:\\"invoiceId\\";i:30;s:12:\\"fullAnalysis\\";b:0;}","batchId":null},"createdAt":1771321006,"delay":null}	0	\N	1771321006	1771321006
\.


--
-- Data for Name: migrations; Type: TABLE DATA; Schema: public; Owner: postgres
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
\.


--
-- Data for Name: notifications; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.notifications (id, company_id, user_id, type, title, message, read, metadata, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: override_logs; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.override_logs (id, invoice_id, company_id, user_id, action, reason, metadata, ip_address, created_at, updated_at) FROM stdin;
1	8	2	4	direct_mis_submission	updated and remove error	{"submission_mode":"direct_mis","user_role":"company_admin"}	10.83.6.125	2026-02-12 08:51:11	2026-02-12 08:51:11
2	9	7	10	direct_mis_submission	OK YAR KR DO SUBMIIT	{"submission_mode":"direct_mis","user_role":"company_admin"}	10.83.4.235	2026-02-13 09:49:14	2026-02-13 09:49:14
3	9	7	10	direct_mis_submission	OK YEH INVOICE TRY KAY LIYE HAI AB	{"submission_mode":"direct_mis","user_role":"company_admin"}	10.83.0.98	2026-02-13 09:53:03	2026-02-13 09:53:03
4	9	7	10	direct_mis_submission	KR DO SUBMIT	{"submission_mode":"direct_mis","user_role":"company_admin"}	10.83.0.98	2026-02-13 09:57:10	2026-02-13 09:57:10
\.


--
-- Data for Name: override_usage_logs; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.override_usage_logs (id, company_id, invoice_id, hs_code, override_layer, override_source_id, original_values, overridden_values, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: password_reset_tokens; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.password_reset_tokens (email, token, created_at) FROM stdin;
\.


--
-- Data for Name: pos_customers; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.pos_customers (id, company_id, name, email, phone, address, city, ntn, cnic, type, is_active, created_at, updated_at) FROM stdin;
6	11	Walk-in Customer	\N	\N	\N	\N	\N	\N	unregistered	t	2026-03-06 10:47:55	2026-03-06 10:47:55
7	11	Ahmed Ali	ahmed@example.com	03001234567	Main Bazar, Lahore	\N	\N	\N	unregistered	t	2026-03-06 10:47:55	2026-03-06 10:47:55
8	11	Fatima Bibi	\N	03219876543	Model Town, Lahore	\N	\N	\N	unregistered	t	2026-03-06 10:47:55	2026-03-06 10:47:55
\.


--
-- Data for Name: pos_payments; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.pos_payments (id, transaction_id, payment_method, amount, reference_number, created_at, updated_at) FROM stdin;
3	15	cash	1160.00	\N	2026-03-09 13:05:10	2026-03-09 13:05:10
4	18	cash	870.00	\N	2026-03-09 13:17:52	2026-03-09 13:17:52
5	19	cash	155.90	\N	2026-03-19 08:36:43	2026-03-19 08:36:43
6	20	qr_payment	590.63	\N	2026-03-19 08:51:08	2026-03-19 08:51:08
7	21	debit_card	473.03	\N	2026-03-19 09:55:16	2026-03-19 09:55:16
8	22	cash	870.00	\N	2026-03-19 10:19:19	2026-03-19 10:19:19
9	23	cash	2902.78	\N	2026-03-19 10:28:38	2026-03-19 10:28:38
10	26	cash	3480.00	\N	2026-03-19 15:13:07	2026-03-19 15:13:07
11	27	credit_card	2411.08	\N	2026-03-24 07:55:56	2026-03-24 07:55:56
12	28	cash	580.00	\N	2026-03-24 08:30:18	2026-03-24 08:30:18
16	29	cash	493.00	\N	2026-03-24 09:25:13	2026-03-24 09:25:13
21	34	cash	580.00	\N	2026-03-24 16:54:23	2026-03-24 16:54:23
\.


--
-- Data for Name: pos_products; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.pos_products (id, company_id, name, description, price, tax_rate, hs_code, uom, category, sku, barcode, is_active, created_at, updated_at, is_tax_exempt) FROM stdin;
17	11	Chai	Doodh patti special	60.00	0.00	\N	NOS	Beverages	CHI-001	\N	t	2026-03-06 10:47:55	2026-03-06 10:47:55	f
18	11	Samosa	Crispy aloo samosa	35.00	16.00	\N	NOS	Snacks	SAM-001	\N	t	2026-03-06 10:47:55	2026-03-06 10:47:55	f
19	11	Paratha	Aloo paratha with butter	80.00	5.00	\N	NOS	Food	PAR-001	\N	t	2026-03-06 10:47:55	2026-03-06 10:47:55	f
20	11	Lassi	Mango lassi glass	100.00	0.00	\N	NOS	Beverages	LAS-001	\N	t	2026-03-06 10:47:55	2026-03-06 10:47:55	f
21	11	Chicken Biryani	Full plate chicken biryani	450.00	16.00	\N	NOS	Food	BIR-001	\N	t	2026-03-06 10:47:55	2026-03-06 10:47:55	f
22	11	Cold Drink 500ml	Pepsi/Coke 500ml	120.00	16.00	\N	NOS	Beverages	CD-001	\N	t	2026-03-06 10:47:55	2026-03-06 10:47:55	f
23	11	Naan	Tandoori naan	25.00	0.00	\N	NOS	Food	NAN-001	\N	t	2026-03-06 10:47:55	2026-03-06 10:47:55	f
24	11	Mineral Water 1.5L	Nestle Pure Life	80.00	0.00	\N	NOS	Beverages	MW-001	\N	t	2026-03-06 10:47:55	2026-03-06 10:47:55	f
25	13	Chicken Broast	\N	500.00	0.00	\N	NOS	\N	\N	\N	t	2026-03-09 13:05:10	2026-03-09 13:05:10	f
26	13	Broast Deal	\N	750.00	0.00	\N	NOS	\N	\N	\N	t	2026-03-09 13:17:52	2026-03-09 13:17:52	f
27	13	a	\N	0.00	0.00	\N	NOS	\N	\N	\N	t	2026-03-24 09:26:30	2026-03-24 09:26:30	f
28	13	Chicken	\N	0.00	0.00	\N	NOS	\N	\N	\N	t	2026-03-24 09:49:24	2026-03-24 09:49:24	f
29	13	TEST POS ITEM 2	\N	0.00	0.00	\N	NOS	\N	\N	\N	t	2026-03-24 16:51:55	2026-03-24 16:51:55	f
\.


--
-- Data for Name: pos_services; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.pos_services (id, company_id, name, description, price, tax_rate, is_active, created_at, updated_at, is_tax_exempt) FROM stdin;
\.


--
-- Data for Name: pos_tax_rules; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.pos_tax_rules (id, payment_method, tax_rate, is_active, created_at, updated_at) FROM stdin;
1	cash	16.00	t	2026-03-05 17:09:13	2026-03-05 17:09:13
2	debit_card	5.00	t	2026-03-05 17:09:13	2026-03-05 17:09:13
3	credit_card	5.00	t	2026-03-05 17:09:13	2026-03-05 17:09:13
4	qr_payment	5.00	t	2026-03-05 17:09:13	2026-03-05 17:09:13
\.


--
-- Data for Name: pos_terminals; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.pos_terminals (id, company_id, terminal_name, terminal_code, location, is_active, created_at, updated_at) FROM stdin;
1	11	Main Counter	T001	Front Entrance	t	2026-03-06 10:47:55	2026-03-06 10:47:55
\.


--
-- Data for Name: pos_transaction_items; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.pos_transaction_items (id, transaction_id, item_type, item_id, item_name, quantity, unit_price, subtotal, created_at, updated_at, is_tax_exempt, tax_rate, tax_amount) FROM stdin;
182	28	product	25	Chicken Broast	1	500.00	500.00	2026-03-24 08:30:18	2026-03-24 08:30:18	f	16.00	80.00
188	29	product	25	Chicken Broast	1	500.00	500.00	2026-03-24 09:25:13	2026-03-24 09:25:13	f	16.00	68.00
195	34	product	25	Chicken Broast	1	500.00	500.00	2026-03-24 16:54:23	2026-03-24 16:54:23	f	16.00	80.00
87	22	product	26	Broast Deal	1	750.00	750.00	2026-03-19 10:19:19	2026-03-19 10:19:19	f	0.00	0.00
88	22	service	\N	roti	10	0.00	0.00	2026-03-19 10:19:19	2026-03-19 10:19:19	f	0.00	0.00
89	22	service	\N	cold drink	1	160.00	160.00	2026-03-19 10:19:19	2026-03-19 10:19:19	f	0.00	0.00
29	6	product	\N	Chicken Biryani Special	1	0.00	0.00	2026-03-06 10:56:11	2026-03-06 10:56:11	f	0.00	0.00
30	6	product	\N	Pepsi Cold Drink	3	60.00	180.00	2026-03-06 10:56:11	2026-03-06 10:56:11	f	0.00	0.00
37	15	product	25	Chicken Broast	2	500.00	1000.00	2026-03-09 13:05:10	2026-03-09 13:05:10	f	0.00	0.00
39	18	product	26	Broast Deal	1	750.00	750.00	2026-03-09 13:17:52	2026-03-09 13:17:52	f	0.00	0.00
45	19	service	\N	roti	10	16.00	160.00	2026-03-19 08:36:43	2026-03-19 08:36:43	f	0.00	0.00
47	20	product	26	Broast Deal	1	750.00	750.00	2026-03-19 08:51:08	2026-03-19 08:51:08	f	0.00	0.00
115	23	product	25	Chicken Broast	1	500.00	500.00	2026-03-19 10:28:38	2026-03-19 10:28:38	f	0.00	0.00
116	23	service	\N	roti	3	16.00	48.00	2026-03-19 10:28:38	2026-03-19 10:28:38	f	0.00	0.00
117	23	service	\N	mix sabzi	1	180.00	180.00	2026-03-19 10:28:38	2026-03-19 10:28:38	f	0.00	0.00
118	23	service	\N	chicken karahi 1 kg	1	1600.00	1600.00	2026-03-19 10:28:38	2026-03-19 10:28:38	f	0.00	0.00
119	23	service	\N	tikka boti plate 8pc	1	800.00	800.00	2026-03-19 10:28:38	2026-03-19 10:28:38	f	0.00	0.00
57	21	service	\N	Daal	1	180.00	180.00	2026-03-19 09:55:16	2026-03-19 09:55:16	f	0.00	0.00
58	21	service	\N	roti	10	0.00	0.00	2026-03-19 09:55:16	2026-03-19 09:55:16	f	0.00	0.00
59	21	service	\N	rice	1	350.00	350.00	2026-03-19 09:55:16	2026-03-19 09:55:16	f	0.00	0.00
120	24	product	\N	Ch	1	0.00	0.00	2026-03-19 10:40:13	2026-03-19 10:40:13	f	0.00	0.00
135	26	product	25	Chicken Broast	3	500.00	1500.00	2026-03-19 15:13:07	2026-03-19 15:13:07	f	16.00	240.00
136	26	product	26	Broast Deal	2	750.00	1500.00	2026-03-19 15:13:07	2026-03-19 15:13:07	f	16.00	240.00
176	27	product	25	Chicken Broast	1	500.00	500.00	2026-03-24 07:55:56	2026-03-24 07:55:56	f	5.00	17.50
177	27	service	\N	roti	3	16.00	48.00	2026-03-24 07:55:56	2026-03-24 07:55:56	f	5.00	1.68
178	27	service	\N	mix sabzi	1	180.00	180.00	2026-03-24 07:55:56	2026-03-24 07:55:56	f	5.00	6.30
179	27	service	\N	chicken karahi 1 kg	1	1600.00	1600.00	2026-03-24 07:55:56	2026-03-24 07:55:56	f	5.00	56.00
180	27	service	\N	tikka boti plate 8pc	1	800.00	800.00	2026-03-24 07:55:56	2026-03-24 07:55:56	f	5.00	28.00
181	27	service	\N	1.5 ltr drink	1	160.00	160.00	2026-03-24 07:55:56	2026-03-24 07:55:56	t	0.00	0.00
\.


--
-- Data for Name: pos_transactions; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.pos_transactions (id, company_id, terminal_id, invoice_number, customer_name, customer_phone, subtotal, discount_type, discount_value, discount_amount, tax_rate, tax_amount, total_amount, payment_method, pra_invoice_number, pra_response_code, pra_status, created_by, created_at, updated_at, submission_hash, pra_qr_code, status, locked_by_terminal_id, lock_time, exempt_amount, share_token, share_token_created_at, invoice_mode) FROM stdin;
19	13	\N	POS-2026-00006	\N	\N	160.00	amount	25.60	25.60	16.00	21.50	155.90	cash	191963FCMN583149109	100	submitted	13	2026-03-19 08:36:02	2026-03-19 09:58:03	efbe4e5ae1a73257e64156b7d63b71fb7cc98512c30bf01d6c6ed3545e82c753	data:image/svg+xml;base64,PD94bWwgdmVyc2lvbj0iMS4wIiBlbmNvZGluZz0iVVRGLTgiPz4KPHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZlcnNpb249IjEuMSIgd2lkdGg9IjE1MCIgaGVpZ2h0PSIxNTAiIHZpZXdCb3g9IjAgMCAxNTAgMTUwIj48cmVjdCB4PSIwIiB5PSIwIiB3aWR0aD0iMTUwIiBoZWlnaHQ9IjE1MCIgZmlsbD0iI2ZmZmZmZiIvPjxnIHRyYW5zZm9ybT0ic2NhbGUoMy44NDYpIj48ZyB0cmFuc2Zvcm09InRyYW5zbGF0ZSgxLDEpIj48cGF0aCBmaWxsLXJ1bGU9ImV2ZW5vZGQiIGQ9Ik05IDBMOSAxTDggMUw4IDJMOSAyTDkgM0w4IDNMOCA0TDEwIDRMMTAgN0wxMSA3TDExIDRMMTIgNEwxMiA1TDE1IDVMMTUgNkwxNCA2TDE0IDhMMTMgOEwxMyA2TDEyIDZMMTIgOEw2IDhMNiA5TDggOUw4IDEwTDUgMTBMNSA4TDAgOEwwIDExTDEgMTFMMSAxMkwzIDEyTDMgMTFMNCAxMUw0IDEzTDMgMTNMMyAxNEwyIDE0TDIgMTNMMCAxM0wwIDE0TDEgMTRMMSAxNUwzIDE1TDMgMTdMNCAxN0w0IDE5TDIgMTlMMiAxOEwwIDE4TDAgMTlMMiAxOUwyIDIyTDEgMjJMMSAyMUwwIDIxTDAgMjJMMSAyMkwxIDIzTDAgMjNMMCAyNEwzIDI0TDMgMjNMNCAyM0w0IDI0TDUgMjRMNSAyN0wzIDI3TDMgMjhMNCAyOEw0IDI5TDUgMjlMNSAyOEw2IDI4TDYgMjlMOCAyOUw4IDMxTDkgMzFMOSAzMEwxMyAzMEwxMyAzMUwxMCAzMUwxMCAzM0wxMSAzM0wxMSAzNEwxMiAzNEwxMiAzNkwxMSAzNkwxMSAzNUw5IDM1TDkgMzJMOCAzMkw4IDM3TDEyIDM3TDEyIDM2TDEzIDM2TDEzIDM3TDE0IDM3TDE0IDM2TDE1IDM2TDE1IDM3TDE2IDM3TDE2IDM1TDE4IDM1TDE4IDM2TDE5IDM2TDE5IDM3TDIwIDM3TDIwIDM2TDE5IDM2TDE5IDM0TDIwIDM0TDIwIDM1TDIxIDM1TDIxIDM2TDIyIDM2TDIyIDM3TDIzIDM3TDIzIDM0TDI0IDM0TDI0IDM2TDI1IDM2TDI1IDM3TDI3IDM3TDI3IDM2TDI5IDM2TDI5IDM3TDMwIDM3TDMwIDM2TDMxIDM2TDMxIDM1TDMyIDM1TDMyIDM0TDMzIDM0TDMzIDM1TDM2IDM1TDM2IDM2TDM0IDM2TDM0IDM3TDM3IDM3TDM3IDM0TDM1IDM0TDM1IDMzTDM3IDMzTDM3IDMyTDM2IDMyTDM2IDMxTDM3IDMxTDM3IDMwTDM0IDMwTDM0IDMxTDMzIDMxTDMzIDI5TDM1IDI5TDM1IDI4TDI5IDI4TDI5IDI3TDMwIDI3TDMwIDI2TDI5IDI2TDI5IDI3TDI4IDI3TDI4IDI2TDI2IDI2TDI2IDI1TDI3IDI1TDI3IDIzTDI4IDIzTDI4IDI0TDI5IDI0TDI5IDIzTDI4IDIzTDI4IDIyTDI5IDIyTDI5IDIxTDMxIDIxTDMxIDIwTDMyIDIwTDMyIDIxTDMzIDIxTDMzIDIzTDM2IDIzTDM2IDI0TDMyIDI0TDMyIDI1TDMxIDI1TDMxIDI0TDMwIDI0TDMwIDI1TDMxIDI1TDMxIDI2TDMyIDI2TDMyIDI3TDMzIDI3TDMzIDI2TDMyIDI2TDMyIDI1TDM1IDI1TDM1IDI3TDM2IDI3TDM2IDI4TDM3IDI4TDM3IDI2TDM2IDI2TDM2IDI1TDM3IDI1TDM3IDIyTDM2IDIyTDM2IDIxTDM3IDIxTDM3IDE4TDM2IDE4TDM2IDE3TDM3IDE3TDM3IDE0TDM2IDE0TDM2IDEzTDM3IDEzTDM3IDEyTDM2IDEyTDM2IDExTDM3IDExTDM3IDEwTDM2IDEwTDM2IDhMMzUgOEwzNSA5TDM0IDlMMzQgOEwzMyA4TDMzIDEyTDMyIDEyTDMyIDhMMzEgOEwzMSAxMkwyOSAxMkwyOSAxMUwzMCAxMUwzMCAxMEwyOSAxMEwyOSA5TDMwIDlMMzAgOEwyOSA4TDI5IDlMMjcgOUwyNyA4TDI4IDhMMjggN0wyOSA3TDI5IDRMMjcgNEwyNyAzTDI5IDNMMjkgMEwyNiAwTDI2IDFMMjQgMUwyNCAwTDIzIDBMMjMgMUwyNCAxTDI0IDJMMjEgMkwyMSAzTDIwIDNMMjAgMkwxOCAyTDE4IDNMMTYgM0wxNiAyTDE3IDJMMTcgMUwxNSAxTDE1IDBMMTQgMEwxNCAxTDE1IDFMMTUgMkwxNCAyTDE0IDNMMTIgM0wxMiAyTDEzIDJMMTMgMUwxMSAxTDExIDJMMTAgMkwxMCAwWk0xOSAwTDE5IDFMMjIgMUwyMiAwWk0yNiAxTDI2IDJMMjUgMkwyNSAzTDIzIDNMMjMgNEwyMiA0TDIyIDNMMjEgM0wyMSA1TDIyIDVMMjIgNkwyMSA2TDIxIDdMMjIgN0wyMiA4TDIzIDhMMjMgOUwyNCA5TDI0IDEwTDIyIDEwTDIyIDEyTDIzIDEyTDIzIDEzTDIxIDEzTDIxIDE1TDIyIDE1TDIyIDE0TDIzIDE0TDIzIDE1TDI1IDE1TDI1IDE2TDIzIDE2TDIzIDE4TDE5IDE4TDE5IDE3TDIxIDE3TDIxIDE2TDIwIDE2TDIwIDE0TDE5IDE0TDE5IDEzTDIwIDEzTDIwIDEyTDIxIDEyTDIxIDEwTDE5IDEwTDE5IDExTDE4IDExTDE4IDlMMTkgOUwxOSA4TDE4IDhMMTggNkwxOSA2TDE5IDdMMjAgN0wyMCA1TDE4IDVMMTggNkwxNyA2TDE3IDdMMTYgN0wxNiA2TDE1IDZMMTUgN0wxNiA3TDE2IDhMMTggOEwxOCA5TDE1IDlMMTUgOEwxNCA4TDE0IDlMMTMgOUwxMyA4TDEyIDhMMTIgOUwxMyA5TDEzIDEwTDE0IDEwTDE0IDlMMTUgOUwxNSAxMEwxNyAxMEwxNyAxMkwxOCAxMkwxOCAxM0wxNiAxM0wxNiAxMkwxNSAxMkwxNSAxMUwxMyAxMUwxMyAxMkwxMiAxMkwxMiAxMEwxMSAxMEwxMSA5TDEwIDlMMTAgMTBMOSAxMEw5IDExTDggMTFMOCAxNEw3IDE0TDcgMTNMNCAxM0w0IDE0TDMgMTRMMyAxNUw0IDE1TDQgMTRMNyAxNEw3IDE1TDYgMTVMNiAxNkw3IDE2TDcgMTdMNSAxN0w1IDE4TDcgMThMNyAxOUw2IDE5TDYgMjBMNyAyMEw3IDIxTDUgMjFMNSAyMEw0IDIwTDQgMjJMNyAyMkw3IDIxTDggMjFMOCAyMkw5IDIyTDkgMjNMMTAgMjNMMTAgMjFMOSAyMUw5IDIwTDEwIDIwTDEwIDE5TDkgMTlMOSAyMEw3IDIwTDcgMTlMOCAxOUw4IDE4TDcgMThMNyAxN0wxMyAxN0wxMyAxOEwxMSAxOEwxMSAyMEwxMiAyMEwxMiAyMUwxMyAyMUwxMyAyMkwxNCAyMkwxNCAyM0wxMiAyM0wxMiAyMkwxMSAyMkwxMSAyNEwxMCAyNEwxMCAyNUw5IDI1TDkgMjZMOCAyNkw4IDIzTDYgMjNMNiAyNEw3IDI0TDcgMjVMNiAyNUw2IDI2TDcgMjZMNyAyN0w2IDI3TDYgMjhMNyAyOEw3IDI3TDkgMjdMOSAyOUwxMCAyOUwxMCAyOEwxMSAyOEwxMSAyOUwxNCAyOUwxNCAzMEwxNiAzMEwxNiAyOUwxNyAyOUwxNyAzMUwxNiAzMUwxNiAzMkwxNSAzMkwxNSAzMUwxMyAzMUwxMyAzMkwxMSAzMkwxMSAzM0wxMyAzM0wxMyAzNkwxNCAzNkwxNCAzM0wxNyAzM0wxNyAzNEwxOSAzNEwxOSAzM0wyMSAzM0wyMSAzNUwyMiAzNUwyMiAzNEwyMyAzNEwyMyAzM0wyMiAzM0wyMiAzMkwyMyAzMkwyMyAzMUwyNCAzMUwyNCAzMkwyOCAzMkwyOCAyOUwyNyAyOUwyNyAzMEwyNiAzMEwyNiAyOEwyOCAyOEwyOCAyN0wyNiAyN0wyNiAyNkwyNSAyNkwyNSAyNEwyNiAyNEwyNiAyM0wyNyAyM0wyNyAyMkwyOCAyMkwyOCAyMUwyNSAyMUwyNSAyM0wyNCAyM0wyNCAyMkwyMyAyMkwyMyAyMUwyMSAyMUwyMSAyMkwyMiAyMkwyMiAyM0wyMyAyM0wyMyAyNUwyMiAyNUwyMiAyNEwyMSAyNEwyMSAyN0wyMiAyN0wyMiAyOEwyMCAyOEwyMCAyNUwxOCAyNUwxOCAyN0wxNyAyN0wxNyAyNkwxNiAyNkwxNiAyNUwxNCAyNUwxNCAyM0wxNSAyM0wxNSAyNEwxNiAyNEwxNiAyM0wxNyAyM0wxNyAyNEwxOCAyNEwxOCAyMkwxOSAyMkwxOSAyM0wyMCAyM0wyMCAyMEwyMSAyMEwyMSAxOUwyNCAxOUwyNCAyMEwyNiAyMEwyNiAxOUwyNyAxOUwyNyAxOEwyOCAxOEwyOCAyMEwyOSAyMEwyOSAxOUwzMCAxOUwzMCAxOEwzMSAxOEwzMSAxOUwzMiAxOUwzMiAxOEwzMyAxOEwzMyAyMUwzNCAyMUwzNCAyMkwzNSAyMkwzNSAyMUwzNCAyMUwzNCAyMEwzNiAyMEwzNiAxOUwzNCAxOUwzNCAxN0wzMiAxN0wzMiAxOEwzMSAxOEwzMSAxN0wyOSAxN0wyOSAxNkwzMyAxNkwzMyAxNUwzNCAxNUwzNCAxNkwzNSAxNkwzNSAxN0wzNiAxN0wzNiAxNUwzNCAxNUwzNCAxM0wzMiAxM0wzMiAxNEwzMSAxNEwzMSAxM0wyOSAxM0wyOSAxMkwyOCAxMkwyOCAxMUwyNyAxMUwyNyA5TDI2IDlMMjYgMTBMMjUgMTBMMjUgOUwyNCA5TDI0IDZMMjUgNkwyNSA4TDI3IDhMMjcgN0wyOCA3TDI4IDZMMjcgNkwyNyA3TDI2IDdMMjYgNkwyNSA2TDI1IDVMMjcgNUwyNyA0TDI2IDRMMjYgMkwyNyAyTDI3IDFaTTEwIDNMMTAgNEwxMSA0TDExIDNaTTE0IDNMMTQgNEwxNSA0TDE1IDVMMTcgNUwxNyA0TDE2IDRMMTYgM1pNMTkgM0wxOSA0TDIwIDRMMjAgM1pNMjMgNEwyMyA1TDI0IDVMMjQgNFpNOCA1TDggN0w5IDdMOSA1Wk0yMiA2TDIyIDdMMjMgN0wyMyA2Wk0yMCA4TDIwIDlMMjEgOUwyMSA4Wk0yIDlMMiAxMEwxIDEwTDEgMTFMMiAxMUwyIDEwTDMgMTBMMyA5Wk00IDEwTDQgMTFMNSAxMUw1IDEyTDcgMTJMNyAxMUw1IDExTDUgMTBaTTEwIDEwTDEwIDE0TDggMTRMOCAxNkwxMCAxNkwxMCAxNUwxMSAxNUwxMSAxNEwxMyAxNEwxMyAxNUwxMiAxNUwxMiAxNkwxMyAxNkwxMyAxNUwxNCAxNUwxNCAxN0wxNSAxN0wxNSAxNUwxNiAxNUwxNiAxNkwxNyAxNkwxNyAxN0wxNiAxN0wxNiAxOEwxMyAxOEwxMyAxOUwxMiAxOUwxMiAyMEwxMyAyMEwxMyAxOUwxNiAxOUwxNiAyMEwxNyAyMEwxNyAyMUwxNSAyMUwxNSAyMEwxNCAyMEwxNCAyMUwxNSAyMUwxNSAyM0wxNiAyM0wxNiAyMkwxOCAyMkwxOCAyMEwyMCAyMEwyMCAxOUwxOSAxOUwxOSAxOEwxOCAxOEwxOCAyMEwxNyAyMEwxNyAxOUwxNiAxOUwxNiAxOEwxNyAxOEwxNyAxN0wxOCAxN0wxOCAxNkwxNyAxNkwxNyAxNUwxOSAxNUwxOSAxNEwxNSAxNEwxNSAxM0wxNCAxM0wxNCAxMkwxMyAxMkwxMyAxM0wxMSAxM0wxMSAxMFpNMzUgMTBMMzUgMTFMMzQgMTFMMzQgMTJMMzUgMTJMMzUgMTNMMzYgMTNMMzYgMTJMMzUgMTJMMzUgMTFMMzYgMTFMMzYgMTBaTTI2IDExTDI2IDEyTDI0IDEyTDI0IDEzTDIzIDEzTDIzIDE0TDI0IDE0TDI0IDEzTDI2IDEzTDI2IDE0TDI1IDE0TDI1IDE1TDI2IDE1TDI2IDE2TDI1IDE2TDI1IDE3TDI3IDE3TDI3IDE1TDI5IDE1TDI5IDE0TDI3IDE0TDI3IDExWk0xMyAxM0wxMyAxNEwxNCAxNEwxNCAxNUwxNSAxNUwxNSAxNEwxNCAxNEwxNCAxM1pNMjYgMTRMMjYgMTVMMjcgMTVMMjcgMTRaTTI4IDE3TDI4IDE4TDI5IDE4TDI5IDE3Wk0yNSAxOEwyNSAxOUwyNiAxOUwyNiAxOFpNMiAyMkwyIDIzTDMgMjNMMyAyMlpNMzAgMjJMMzAgMjNMMzIgMjNMMzIgMjJaTTAgMjVMMCAyOUwxIDI5TDEgMjVaTTMgMjVMMyAyNkw0IDI2TDQgMjVaTTExIDI1TDExIDI2TDE0IDI2TDE0IDI5TDE1IDI5TDE1IDI3TDE2IDI3TDE2IDI4TDE3IDI4TDE3IDI3TDE2IDI3TDE2IDI2TDE0IDI2TDE0IDI1Wk0yMyAyNUwyMyAyNkwyMiAyNkwyMiAyN0wyMyAyN0wyMyAyOEwyMiAyOEwyMiAyOUwxOSAyOUwxOSAzMEwxOCAzMEwxOCAzMkwyMCAzMkwyMCAzMUwyMSAzMUwyMSAzMkwyMiAzMkwyMiAzMUwyMyAzMUwyMyAzMEwyNCAzMEwyNCAyOUwyMyAyOUwyMyAyOEwyNSAyOEwyNSAyNkwyNCAyNkwyNCAyNVpNOSAyNkw5IDI3TDEwIDI3TDEwIDI2Wk0yMyAyNkwyMyAyN0wyNCAyN0wyNCAyNlpNMTIgMjdMMTIgMjhMMTMgMjhMMTMgMjdaTTI5IDI5TDI5IDMyTDMyIDMyTDMyIDI5Wk0xOSAzMEwxOSAzMUwyMCAzMUwyMCAzMFpNMjEgMzBMMjEgMzFMMjIgMzFMMjIgMzBaTTI1IDMwTDI1IDMxTDI2IDMxTDI2IDMwWk0zMCAzMEwzMCAzMUwzMSAzMUwzMSAzMFpNMzQgMzFMMzQgMzJMMzUgMzJMMzUgMzFaTTEzIDMyTDEzIDMzTDE0IDMzTDE0IDMyWk0yNCAzM0wyNCAzNEwyNSAzNEwyNSAzNkwyNiAzNkwyNiAzNEwyNyAzNEwyNyAzNUwyOCAzNUwyOCAzM1pNMzMgMzNMMzMgMzRMMzQgMzRMMzQgMzNaTTI5IDM0TDI5IDM1TDMxIDM1TDMxIDM0Wk0wIDBMMCA3TDcgN0w3IDBaTTEgMUwxIDZMNiA2TDYgMVpNMiAyTDIgNUw1IDVMNSAyWk0zMCAwTDMwIDdMMzcgN0wzNyAwWk0zMSAxTDMxIDZMMzYgNkwzNiAxWk0zMiAyTDMyIDVMMzUgNUwzNSAyWk0wIDMwTDAgMzdMNyAzN0w3IDMwWk0xIDMxTDEgMzZMNiAzNkw2IDMxWk0yIDMyTDIgMzVMNSAzNUw1IDMyWiIgZmlsbD0iIzAwMDAwMCIvPjwvZz48L2c+PC9zdmc+Cg==	completed	\N	\N	0.00	\N	\N	pra
6	12	\N	POS-2026-00001	\N	\N	180.00	percentage	0.00	0.00	16.00	28.80	208.80	cash	\N	\N	local	12	2026-03-06 10:55:20	2026-03-06 10:55:20	\N	\N	draft	\N	2026-03-06 10:55:20	0.00	\N	\N	pra
20	13	\N	POS-2026-00007	\N	\N	750.00	percentage	25.00	187.50	5.00	28.13	590.63	qr_payment	191963FCMN583101308	100	submitted	13	2026-03-19 08:51:07	2026-03-19 09:58:04	476bcedf57d185fcd94eb09aaaf69ada0df6a7a41ba440cb1a1aaccb4174e454	data:image/svg+xml;base64,PD94bWwgdmVyc2lvbj0iMS4wIiBlbmNvZGluZz0iVVRGLTgiPz4KPHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZlcnNpb249IjEuMSIgd2lkdGg9IjE1MCIgaGVpZ2h0PSIxNTAiIHZpZXdCb3g9IjAgMCAxNTAgMTUwIj48cmVjdCB4PSIwIiB5PSIwIiB3aWR0aD0iMTUwIiBoZWlnaHQ9IjE1MCIgZmlsbD0iI2ZmZmZmZiIvPjxnIHRyYW5zZm9ybT0ic2NhbGUoMy44NDYpIj48ZyB0cmFuc2Zvcm09InRyYW5zbGF0ZSgxLDEpIj48cGF0aCBmaWxsLXJ1bGU9ImV2ZW5vZGQiIGQ9Ik05IDBMOSAxTDggMUw4IDJMOSAyTDkgM0w4IDNMOCA0TDEwIDRMMTAgN0wxMSA3TDExIDRMMTIgNEwxMiA4TDYgOEw2IDlMNSA5TDUgOEwwIDhMMCA5TDMgOUwzIDEwTDIgMTBMMiAxMUwxIDExTDEgMTBMMCAxMEwwIDExTDEgMTFMMSAxMkwyIDEyTDIgMTNMMCAxM0wwIDE0TDEgMTRMMSAxNUwwIDE1TDAgMTdMMSAxN0wxIDE1TDIgMTVMMiAxN0w0IDE3TDQgMThMMiAxOEwyIDE5TDAgMTlMMCAyMkwxIDIyTDEgMjNMMCAyM0wwIDI5TDEgMjlMMSAyNUwzIDI1TDMgMjNMNCAyM0w0IDIyTDUgMjJMNSAyMUw4IDIxTDggMjBMMTAgMjBMMTAgMjFMMTEgMjFMMTEgMjJMMTIgMjJMMTIgMjRMMTMgMjRMMTMgMjNMMTQgMjNMMTQgMjVMMTAgMjVMMTAgMjRMMTEgMjRMMTEgMjNMNyAyM0w3IDIyTDYgMjJMNiAyM0w1IDIzTDUgMjRMNCAyNEw0IDI1TDUgMjVMNSAyN0w3IDI3TDcgMjZMOCAyNkw4IDI1TDcgMjVMNyAyNEw5IDI0TDkgMjVMMTAgMjVMMTAgMjdMMTEgMjdMMTEgMjZMMTQgMjZMMTQgMjlMMTEgMjlMMTEgMjhMMTAgMjhMMTAgMzBMOSAzMEw5IDI3TDggMjdMOCAzMUwxMCAzMUwxMCAzMkwxMyAzMkwxMyAzM0wxMSAzM0wxMSAzNEwxMiAzNEwxMiAzNkwxMSAzNkwxMSAzNUwxMCAzNUwxMCAzM0w5IDMzTDkgMzJMOCAzMkw4IDM3TDkgMzdMOSAzNUwxMCAzNUwxMCAzNkwxMSAzNkwxMSAzN0wxMiAzN0wxMiAzNkwxMyAzNkwxMyAzN0wxNCAzN0wxNCAzNkwxNSAzNkwxNSAzN0wxNiAzN0wxNiAzNUwxOCAzNUwxOCAzNkwxOSAzNkwxOSAzN0wyMCAzN0wyMCAzNkwxOSAzNkwxOSAzNEwyMCAzNEwyMCAzNUwyMSAzNUwyMSAzNkwyMiAzNkwyMiAzN0wyMyAzN0wyMyAzNEwyNCAzNEwyNCAzNkwyNSAzNkwyNSAzN0wyNyAzN0wyNyAzNkwyOSAzNkwyOSAzN0wzMCAzN0wzMCAzNkwzMSAzNkwzMSAzNUwzMiAzNUwzMiAzNEwzMyAzNEwzMyAzNUwzNiAzNUwzNiAzNkwzNCAzNkwzNCAzN0wzNyAzN0wzNyAzNEwzNSAzNEwzNSAzM0wzNyAzM0wzNyAzMkwzNiAzMkwzNiAzMUwzNyAzMUwzNyAzMEwzNCAzMEwzNCAzMUwzMyAzMUwzMyAyOUwzNSAyOUwzNSAyOEwyOSAyOEwyOSAyN0wzMCAyN0wzMCAyNkwyOSAyNkwyOSAyN0wyOCAyN0wyOCAyNkwyNiAyNkwyNiAyNUwyNyAyNUwyNyAyM0wyOCAyM0wyOCAyNEwyOSAyNEwyOSAyM0wyOCAyM0wyOCAyMkwyOSAyMkwyOSAyMUwzMSAyMUwzMSAyMEwzMiAyMEwzMiAyMUwzMyAyMUwzMyAyM0wzNiAyM0wzNiAyNEwzMiAyNEwzMiAyNUwzMSAyNUwzMSAyNEwzMCAyNEwzMCAyNUwzMSAyNUwzMSAyNkwzMiAyNkwzMiAyN0wzMyAyN0wzMyAyNkwzMiAyNkwzMiAyNUwzNSAyNUwzNSAyN0wzNiAyN0wzNiAyOEwzNyAyOEwzNyAyNkwzNiAyNkwzNiAyNUwzNyAyNUwzNyAyMkwzNiAyMkwzNiAyMUwzNyAyMUwzNyAxOEwzNiAxOEwzNiAxN0wzNyAxN0wzNyAxNEwzNiAxNEwzNiAxM0wzNyAxM0wzNyAxMkwzNiAxMkwzNiAxMUwzNyAxMUwzNyAxMEwzNiAxMEwzNiA4TDM1IDhMMzUgOUwzNCA5TDM0IDhMMzMgOEwzMyAxMkwzMiAxMkwzMiA4TDMxIDhMMzEgMTJMMjkgMTJMMjkgMTFMMzAgMTFMMzAgMTBMMjkgMTBMMjkgOUwzMCA5TDMwIDhMMjkgOEwyOSA5TDI3IDlMMjcgOEwyOCA4TDI4IDdMMjkgN0wyOSA0TDI3IDRMMjcgM0wyOSAzTDI5IDBMMjYgMEwyNiAxTDI0IDFMMjQgMEwyMyAwTDIzIDFMMjQgMUwyNCAyTDIxIDJMMjEgM0wyMCAzTDIwIDJMMTggMkwxOCAzTDE2IDNMMTYgMkwxNyAyTDE3IDFMMTUgMUwxNSAwTDE0IDBMMTQgMUwxNSAxTDE1IDJMMTQgMkwxNCAzTDEyIDNMMTIgMkwxMyAyTDEzIDFMMTEgMUwxMSAyTDEwIDJMMTAgMFpNMTkgMEwxOSAxTDIyIDFMMjIgMFpNMjYgMUwyNiAyTDI1IDJMMjUgM0wyMyAzTDIzIDRMMjIgNEwyMiAzTDIxIDNMMjEgNUwyMiA1TDIyIDZMMjEgNkwyMSA3TDIyIDdMMjIgOEwyMyA4TDIzIDlMMjQgOUwyNCAxMEwyMiAxMEwyMiAxMkwyMyAxMkwyMyAxM0wyMSAxM0wyMSAxNUwyMiAxNUwyMiAxNEwyMyAxNEwyMyAxNUwyNSAxNUwyNSAxNkwyMyAxNkwyMyAxOEwxOSAxOEwxOSAxN0wyMSAxN0wyMSAxNkwyMCAxNkwyMCAxNEwxOSAxNEwxOSAxM0wyMCAxM0wyMCAxMkwyMSAxMkwyMSAxMEwxOSAxMEwxOSAxMUwxOCAxMUwxOCA5TDE5IDlMMTkgOEwxOCA4TDE4IDZMMTkgNkwxOSA3TDIwIDdMMjAgNUwxOCA1TDE4IDZMMTcgNkwxNyA3TDE2IDdMMTYgNkwxNSA2TDE1IDVMMTcgNUwxNyA0TDE2IDRMMTYgM0wxNCAzTDE0IDRMMTUgNEwxNSA1TDEzIDVMMTMgOEwxMiA4TDEyIDlMMTMgOUwxMyAxMEwxNCAxMEwxNCA5TDE1IDlMMTUgMTBMMTcgMTBMMTcgMTJMMTggMTJMMTggMTNMMTYgMTNMMTYgMTJMMTUgMTJMMTUgMTFMMTMgMTFMMTMgMTJMMTIgMTJMMTIgMTBMMTEgMTBMMTEgOUw5IDlMOSAxMEw4IDEwTDggOUw2IDlMNiAxMEw3IDEwTDcgMTFMNiAxMUw2IDEyTDcgMTJMNyAxMUwxMCAxMUwxMCAxMEwxMSAxMEwxMSAxM0wxMCAxM0wxMCAxMkw5IDEyTDkgMTRMMTEgMTRMMTEgMTVMMTMgMTVMMTMgMTZMOSAxNkw5IDE3TDEwIDE3TDEwIDE4TDggMThMOCAxNkw3IDE2TDcgMTVMOCAxNUw4IDE0TDcgMTRMNyAxM0w2IDEzTDYgMTRMNSAxNEw1IDE4TDQgMThMNCAxOUw1IDE5TDUgMjBMOCAyMEw4IDE5TDEwIDE5TDEwIDE4TDExIDE4TDExIDIxTDEzIDIxTDEzIDIyTDE0IDIyTDE0IDIzTDE1IDIzTDE1IDI0TDE2IDI0TDE2IDIzTDE3IDIzTDE3IDI0TDE4IDI0TDE4IDIyTDE5IDIyTDE5IDIzTDIwIDIzTDIwIDIwTDIxIDIwTDIxIDE5TDI0IDE5TDI0IDIwTDI2IDIwTDI2IDE5TDI3IDE5TDI3IDE4TDI4IDE4TDI4IDIwTDI5IDIwTDI5IDE5TDMwIDE5TDMwIDE4TDMxIDE4TDMxIDE5TDMyIDE5TDMyIDE4TDMzIDE4TDMzIDIxTDM0IDIxTDM0IDIyTDM1IDIyTDM1IDIxTDM0IDIxTDM0IDIwTDM2IDIwTDM2IDE5TDM0IDE5TDM0IDE3TDMyIDE3TDMyIDE4TDMxIDE4TDMxIDE3TDI5IDE3TDI5IDE2TDMzIDE2TDMzIDE1TDM0IDE1TDM0IDE2TDM1IDE2TDM1IDE3TDM2IDE3TDM2IDE1TDM0IDE1TDM0IDEzTDMyIDEzTDMyIDE0TDMxIDE0TDMxIDEzTDI5IDEzTDI5IDEyTDI4IDEyTDI4IDExTDI3IDExTDI3IDlMMjYgOUwyNiAxMEwyNSAxMEwyNSA5TDI0IDlMMjQgNkwyNSA2TDI1IDhMMjcgOEwyNyA3TDI4IDdMMjggNkwyNyA2TDI3IDdMMjYgN0wyNiA2TDI1IDZMMjUgNUwyNyA1TDI3IDRMMjYgNEwyNiAyTDI3IDJMMjcgMVpNMTAgM0wxMCA0TDExIDRMMTEgM1pNMTkgM0wxOSA0TDIwIDRMMjAgM1pNMjMgNEwyMyA1TDI0IDVMMjQgNFpNOCA1TDggN0w5IDdMOSA1Wk0xNCA2TDE0IDhMMTMgOEwxMyA5TDE0IDlMMTQgOEwxNSA4TDE1IDlMMTggOUwxOCA4TDE2IDhMMTYgN0wxNSA3TDE1IDZaTTIyIDZMMjIgN0wyMyA3TDIzIDZaTTIwIDhMMjAgOUwyMSA5TDIxIDhaTTQgOUw0IDEwTDMgMTBMMyAxMUwyIDExTDIgMTJMMyAxMkwzIDExTDUgMTFMNSA5Wk0zNSAxMEwzNSAxMUwzNCAxMUwzNCAxMkwzNSAxMkwzNSAxM0wzNiAxM0wzNiAxMkwzNSAxMkwzNSAxMUwzNiAxMUwzNiAxMFpNMjYgMTFMMjYgMTJMMjQgMTJMMjQgMTNMMjMgMTNMMjMgMTRMMjQgMTRMMjQgMTNMMjYgMTNMMjYgMTRMMjUgMTRMMjUgMTVMMjYgMTVMMjYgMTZMMjUgMTZMMjUgMTdMMjcgMTdMMjcgMTVMMjkgMTVMMjkgMTRMMjcgMTRMMjcgMTFaTTQgMTJMNCAxM0wyIDEzTDIgMTRMMyAxNEwzIDE1TDQgMTVMNCAxM0w1IDEzTDUgMTJaTTEzIDEyTDEzIDEzTDExIDEzTDExIDE0TDEzIDE0TDEzIDE1TDE0IDE1TDE0IDE3TDE1IDE3TDE1IDE1TDE2IDE1TDE2IDE2TDE3IDE2TDE3IDE3TDE2IDE3TDE2IDE4TDEzIDE4TDEzIDE3TDExIDE3TDExIDE4TDEzIDE4TDEzIDE5TDEyIDE5TDEyIDIwTDEzIDIwTDEzIDE5TDE2IDE5TDE2IDIwTDE3IDIwTDE3IDIxTDE1IDIxTDE1IDIwTDE0IDIwTDE0IDIxTDE1IDIxTDE1IDIzTDE2IDIzTDE2IDIyTDE4IDIyTDE4IDIwTDIwIDIwTDIwIDE5TDE5IDE5TDE5IDE4TDE4IDE4TDE4IDIwTDE3IDIwTDE3IDE5TDE2IDE5TDE2IDE4TDE3IDE4TDE3IDE3TDE4IDE3TDE4IDE2TDE3IDE2TDE3IDE1TDE5IDE1TDE5IDE0TDE1IDE0TDE1IDEzTDE0IDEzTDE0IDEyWk0xMyAxM0wxMyAxNEwxNCAxNEwxNCAxNUwxNSAxNUwxNSAxNEwxNCAxNEwxNCAxM1pNNiAxNEw2IDE1TDcgMTVMNyAxNFpNMjYgMTRMMjYgMTVMMjcgMTVMMjcgMTRaTTYgMTZMNiAxN0w3IDE3TDcgMTZaTTI4IDE3TDI4IDE4TDI5IDE4TDI5IDE3Wk01IDE4TDUgMTlMNyAxOUw3IDE4Wk0yNSAxOEwyNSAxOUwyNiAxOUwyNiAxOFpNMiAxOUwyIDIwTDEgMjBMMSAyMkwyIDIyTDIgMjNMMyAyM0wzIDE5Wk0yMSAyMUwyMSAyMkwyMiAyMkwyMiAyM0wyMyAyM0wyMyAyNUwyMiAyNUwyMiAyNEwyMSAyNEwyMSAyN0wyMiAyN0wyMiAyOEwyMCAyOEwyMCAyNUwxOCAyNUwxOCAyN0wxNyAyN0wxNyAyNkwxNiAyNkwxNiAyNUwxNCAyNUwxNCAyNkwxNiAyNkwxNiAyN0wxNSAyN0wxNSAyOUwxNCAyOUwxNCAzMEwxNiAzMEwxNiAyOUwxNyAyOUwxNyAzMUwxNiAzMUwxNiAzMkwxNSAzMkwxNSAzMUwxMyAzMUwxMyAzMEwxMCAzMEwxMCAzMUwxMyAzMUwxMyAzMkwxNCAzMkwxNCAzM0wxMyAzM0wxMyAzNkwxNCAzNkwxNCAzM0wxNyAzM0wxNyAzNEwxOSAzNEwxOSAzM0wyMSAzM0wyMSAzNUwyMiAzNUwyMiAzNEwyMyAzNEwyMyAzM0wyMiAzM0wyMiAzMkwyMyAzMkwyMyAzMUwyNCAzMUwyNCAzMkwyOCAzMkwyOCAyOUwyNyAyOUwyNyAzMEwyNiAzMEwyNiAyOEwyOCAyOEwyOCAyN0wyNiAyN0wyNiAyNkwyNSAyNkwyNSAyNEwyNiAyNEwyNiAyM0wyNyAyM0wyNyAyMkwyOCAyMkwyOCAyMUwyNSAyMUwyNSAyM0wyNCAyM0wyNCAyMkwyMyAyMkwyMyAyMVpNMzAgMjJMMzAgMjNMMzIgMjNMMzIgMjJaTTYgMjNMNiAyNEw1IDI0TDUgMjVMNiAyNUw2IDI2TDcgMjZMNyAyNUw2IDI1TDYgMjRMNyAyNEw3IDIzWk0yMyAyNUwyMyAyNkwyMiAyNkwyMiAyN0wyMyAyN0wyMyAyOEwyMiAyOEwyMiAyOUwxOSAyOUwxOSAzMEwxOCAzMEwxOCAzMkwyMCAzMkwyMCAzMUwyMSAzMUwyMSAzMkwyMiAzMkwyMiAzMUwyMyAzMUwyMyAzMEwyNCAzMEwyNCAyOUwyMyAyOUwyMyAyOEwyNSAyOEwyNSAyNkwyNCAyNkwyNCAyNVpNMjMgMjZMMjMgMjdMMjQgMjdMMjQgMjZaTTIgMjdMMiAyOEwzIDI4TDMgMjlMNyAyOUw3IDI4TDQgMjhMNCAyN1pNMTIgMjdMMTIgMjhMMTMgMjhMMTMgMjdaTTE2IDI3TDE2IDI4TDE3IDI4TDE3IDI3Wk0yOSAyOUwyOSAzMkwzMiAzMkwzMiAyOVpNMTkgMzBMMTkgMzFMMjAgMzFMMjAgMzBaTTIxIDMwTDIxIDMxTDIyIDMxTDIyIDMwWk0yNSAzMEwyNSAzMUwyNiAzMUwyNiAzMFpNMzAgMzBMMzAgMzFMMzEgMzFMMzEgMzBaTTM0IDMxTDM0IDMyTDM1IDMyTDM1IDMxWk0yNCAzM0wyNCAzNEwyNSAzNEwyNSAzNkwyNiAzNkwyNiAzNEwyNyAzNEwyNyAzNUwyOCAzNUwyOCAzM1pNMzMgMzNMMzMgMzRMMzQgMzRMMzQgMzNaTTI5IDM0TDI5IDM1TDMxIDM1TDMxIDM0Wk0wIDBMMCA3TDcgN0w3IDBaTTEgMUwxIDZMNiA2TDYgMVpNMiAyTDIgNUw1IDVMNSAyWk0zMCAwTDMwIDdMMzcgN0wzNyAwWk0zMSAxTDMxIDZMMzYgNkwzNiAxWk0zMiAyTDMyIDVMMzUgNUwzNSAyWk0wIDMwTDAgMzdMNyAzN0w3IDMwWk0xIDMxTDEgMzZMNiAzNkw2IDMxWk0yIDMyTDIgMzVMNSAzNUw1IDMyWiIgZmlsbD0iIzAwMDAwMCIvPjwvZz48L2c+PC9zdmc+Cg==	completed	\N	\N	0.00	\N	\N	pra
21	13	\N	POS-2026-00008	jaafir	\N	530.00	percentage	15.00	79.50	5.00	22.53	473.03	debit_card	191963FCMO022408972	100	submitted	13	2026-03-19 09:54:09	2026-03-19 10:00:23	2c97c590542f0073b196203cfd3a5032c84dd295aead47231a84e7f48d40bbe9	data:image/svg+xml;base64,PD94bWwgdmVyc2lvbj0iMS4wIiBlbmNvZGluZz0iVVRGLTgiPz4KPHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZlcnNpb249IjEuMSIgd2lkdGg9IjE1MCIgaGVpZ2h0PSIxNTAiIHZpZXdCb3g9IjAgMCAxNTAgMTUwIj48cmVjdCB4PSIwIiB5PSIwIiB3aWR0aD0iMTUwIiBoZWlnaHQ9IjE1MCIgZmlsbD0iI2ZmZmZmZiIvPjxnIHRyYW5zZm9ybT0ic2NhbGUoMy44NDYpIj48ZyB0cmFuc2Zvcm09InRyYW5zbGF0ZSgxLDEpIj48cGF0aCBmaWxsLXJ1bGU9ImV2ZW5vZGQiIGQ9Ik0xMCAwTDEwIDFMOCAxTDggM0w5IDNMOSA0TDggNEw4IDhMNiA4TDYgOUw0IDlMNCA4TDMgOEwzIDlMMiA5TDIgOEwwIDhMMCAxMkwxIDEyTDEgMTNMMCAxM0wwIDE0TDEgMTRMMSAxNUwwIDE1TDAgMTZMMiAxNkwyIDE3TDAgMTdMMCAxOEwxIDE4TDEgMTlMMCAxOUwwIDIwTDEgMjBMMSAyMkwwIDIyTDAgMjNMMSAyM0wxIDIyTDIgMjJMMiAxOUwzIDE5TDMgMjBMNSAyMEw1IDE5TDcgMTlMNyAyMEw2IDIwTDYgMjFMNSAyMUw1IDIyTDQgMjJMNCAyMUwzIDIxTDMgMjNMNCAyM0w0IDI0TDMgMjRMMyAyNUw0IDI1TDQgMjRMNSAyNEw1IDIyTDYgMjJMNiAyM0w3IDIzTDcgMjRMNiAyNEw2IDI1TDUgMjVMNSAyNkw2IDI2TDYgMjdMNyAyN0w3IDI2TDYgMjZMNiAyNUw3IDI1TDcgMjRMOCAyNEw4IDIzTDkgMjNMOSAyMUwxMCAyMUwxMCAyNEw5IDI0TDkgMjVMMTAgMjVMMTAgMjdMMTEgMjdMMTEgMjhMMTAgMjhMMTAgMjlMMTEgMjlMMTEgMzBMMTIgMzBMMTIgMzFMMTQgMzFMMTQgMzJMMTMgMzJMMTMgMzRMMTIgMzRMMTIgMzVMMTEgMzVMMTEgMzRMMTAgMzRMMTAgMzJMMTEgMzJMMTEgMzNMMTIgMzNMMTIgMzJMMTEgMzJMMTEgMzFMMTAgMzFMMTAgMzJMOSAzMkw5IDMzTDggMzNMOCAzNEw5IDM0TDkgMzVMOCAzNUw4IDM3TDkgMzdMOSAzNkwxMCAzNkwxMCAzN0wxMSAzN0wxMSAzNkwxMiAzNkwxMiAzNUwxMyAzNUwxMyAzN0wxNSAzN0wxNSAzNkwxNiAzNkwxNiAzN0wxNyAzN0wxNyAzNkwxNiAzNkwxNiAzNUwxOSAzNUwxOSAzN0wyMiAzN0wyMiAzNkwyMyAzNkwyMyAzNEwyMSAzNEwyMSAzM0wyMiAzM0wyMiAzMkwyMyAzMkwyMyAzMUwyMiAzMUwyMiAzMEwyNSAzMEwyNSAzMUwyNCAzMUwyNCAzNUwyNSAzNUwyNSAzN0wyNiAzN0wyNiAzM0wyNyAzM0wyNyAzMkwyNSAzMkwyNSAzMUwyOCAzMUwyOCAzNEwyNyAzNEwyNyAzN0wzMCAzN0wzMCAzNUwyOSAzNUwyOSAzM0wzMiAzM0wzMiAzNUwzMSAzNUwzMSAzNkwzMiAzNkwzMiAzN0wzNCAzN0wzNCAzNkwzMyAzNkwzMyAzMkwzNSAzMkwzNSAzMUwzNiAzMUwzNiAzM0wzNCAzM0wzNCAzNUwzNyAzNUwzNyAyOUwzNiAyOUwzNiAzMEwzNSAzMEwzNSAzMUwzNCAzMUwzNCAzMEwzMyAzMEwzMyAyOEwzMiAyOEwzMiAyN0wzNSAyN0wzNSAyOEwzNCAyOEwzNCAyOUwzNSAyOUwzNSAyOEwzNiAyOEwzNiAyN0wzNyAyN0wzNyAyNEwzNSAyNEwzNSAyNUwzNCAyNUwzNCAyNkwzMiAyNkwzMiAyNUwzMSAyNUwzMSAyNEwzMyAyNEwzMyAyMkwzNCAyMkwzNCAyM0wzNyAyM0wzNyAyMEwzNiAyMEwzNiAxOUwzNyAxOUwzNyAxNkwzNiAxNkwzNiAxNUwzNyAxNUwzNyA5TDM2IDlMMzYgOEwzNCA4TDM0IDlMMzMgOUwzMyA4TDMwIDhMMzAgOUwyOSA5TDI5IDRMMjggNEwyOCA1TDI3IDVMMjcgMkwyOCAyTDI4IDFMMjcgMUwyNyAyTDI2IDJMMjYgMUwyNSAxTDI1IDJMMjQgMkwyNCAwTDIyIDBMMjIgMkwyNCAyTDI0IDNMMjIgM0wyMiA0TDIxIDRMMjEgM0wyMCAzTDIwIDJMMTkgMkwxOSAxTDIwIDFMMjAgMEwxOSAwTDE5IDFMMTcgMUwxNyAwTDE1IDBMMTUgMkwxNCAyTDE0IDNMMTUgM0wxNSAyTDE2IDJMMTYgNEwxNSA0TDE1IDVMMTMgNUwxMyA0TDEyIDRMMTIgNUwxMyA1TDEzIDZMMTIgNkwxMiA3TDEzIDdMMTMgNkwxNCA2TDE0IDhMMTMgOEwxMyAxMEwxNSAxMEwxNSA5TDE0IDlMMTQgOEwxOCA4TDE4IDdMMTkgN0wxOSA5TDIwIDlMMjAgMTBMMTggMTBMMTggOUwxNyA5TDE3IDEwTDE2IDEwTDE2IDExTDExIDExTDExIDEwTDkgMTBMOSAxMUwxMSAxMUwxMSAxMkwxMyAxMkwxMyAxM0wxNCAxM0wxNCAxNEwxMyAxNEwxMyAxOEwxMiAxOEwxMiAxNEwxMSAxNEwxMSAxM0wxMCAxM0wxMCAxNEwxMSAxNEwxMSAxNUw5IDE1TDkgMTJMOCAxMkw4IDhMOSA4TDkgNUwxMCA1TDEwIDhMMTEgOEwxMSA5TDEyIDlMMTIgOEwxMSA4TDExIDRMMTAgNEwxMCAzTDkgM0w5IDJMMTEgMkwxMSAwWk0xNiAxTDE2IDJMMTcgMkwxNyAxWk0yNSAyTDI1IDNMMjYgM0wyNiAyWk0xNyAzTDE3IDRMMTYgNEwxNiA1TDE3IDVMMTcgNEwxOSA0TDE5IDVMMjAgNUwyMCA2TDE5IDZMMTkgN0wyMCA3TDIwIDZMMjEgNkwyMSA3TDIyIDdMMjIgNkwyMyA2TDIzIDdMMjQgN0wyNCA4TDI3IDhMMjcgN0wyOCA3TDI4IDZMMjcgNkwyNyA1TDI1IDVMMjUgNEwyNCA0TDI0IDVMMjMgNUwyMyA0TDIyIDRMMjIgNkwyMSA2TDIxIDRMMTkgNEwxOSAzWk0yNCA1TDI0IDdMMjUgN0wyNSA1Wk0xNSA2TDE1IDdMMTYgN0wxNiA2Wk0xNyA2TDE3IDdMMTggN0wxOCA2Wk0yNiA2TDI2IDdMMjcgN0wyNyA2Wk0yMCA4TDIwIDlMMjMgOUwyMyAxMEwyMSAxMEwyMSAxMUwyMiAxMUwyMiAxMkwxOCAxMkwxOCAxMEwxNyAxMEwxNyAxMkwxNiAxMkwxNiAxM0wxNSAxM0wxNSAxNEwxNCAxNEwxNCAxNUwxNSAxNUwxNSAxNkwxNCAxNkwxNCAxN0wxNSAxN0wxNSAxOEwxNCAxOEwxNCAxOUwxMiAxOUwxMiAxOEwxMSAxOEwxMSAxN0wxMCAxN0wxMCAxNkw5IDE2TDkgMTVMNSAxNUw1IDE0TDggMTRMOCAxMkw3IDEyTDcgMTFMNiAxMUw2IDEyTDcgMTJMNyAxM0w1IDEzTDUgMTJMNCAxMkw0IDExTDUgMTFMNSAxMEw0IDEwTDQgMTFMMyAxMUwzIDEwTDIgMTBMMiA5TDEgOUwxIDEwTDIgMTBMMiAxM0wxIDEzTDEgMTRMMiAxNEwyIDEzTDQgMTNMNCAxNkwzIDE2TDMgMTdMMiAxN0wyIDE4TDMgMThMMyAxOUw1IDE5TDUgMThMOSAxOEw5IDE5TDggMTlMOCAyMEw5IDIwTDkgMTlMMTAgMTlMMTAgMjFMMTEgMjFMMTEgMjZMMTMgMjZMMTMgMjdMMTQgMjdMMTQgMjZMMTUgMjZMMTUgMjhMMTQgMjhMMTQgMjlMMTMgMjlMMTMgMjhMMTEgMjhMMTEgMjlMMTIgMjlMMTIgMzBMMTQgMzBMMTQgMzFMMTggMzFMMTggMzNMMTkgMzNMMTkgMzRMMjAgMzRMMjAgMzVMMjEgMzVMMjEgMzRMMjAgMzRMMjAgMzNMMjEgMzNMMjEgMjlMMTkgMjlMMTkgMjhMMTYgMjhMMTYgMjZMMTcgMjZMMTcgMjdMMjAgMjdMMjAgMjVMMjEgMjVMMjEgMjZMMjIgMjZMMjIgMjhMMjMgMjhMMjMgMjlMMjUgMjlMMjUgMjdMMjYgMjdMMjYgMzBMMjggMzBMMjggMjVMMjcgMjVMMjcgMjRMMjggMjRMMjggMjNMMjkgMjNMMjkgMjVMMzAgMjVMMzAgMjJMMjggMjJMMjggMjFMMzAgMjFMMzAgMjBMMzEgMjBMMzEgMjFMMzIgMjFMMzIgMjJMMzEgMjJMMzEgMjNMMzIgMjNMMzIgMjJMMzMgMjJMMzMgMjFMMzIgMjFMMzIgMjBMMzEgMjBMMzEgMTlMMzMgMTlMMzMgMjBMMzQgMjBMMzQgMjJMMzYgMjJMMzYgMjBMMzQgMjBMMzQgMTlMMzUgMTlMMzUgMThMMzQgMThMMzQgMTZMMzUgMTZMMzUgMTdMMzYgMTdMMzYgMTZMMzUgMTZMMzUgMTVMMzYgMTVMMzYgMTJMMzUgMTJMMzUgMTFMMzYgMTFMMzYgOUwzNSA5TDM1IDExTDM0IDExTDM0IDEwTDMzIDEwTDMzIDlMMzEgOUwzMSAxMEwzMCAxMEwzMCAxMUwyOSAxMUwyOSAxMkwzMCAxMkwzMCAxMUwzMSAxMUwzMSAxMkwzMiAxMkwzMiAxNEwzMCAxNEwzMCAxNUwzMiAxNUwzMiAxNkwzMSAxNkwzMSAxOUwzMCAxOUwzMCAxN0wyOSAxN0wyOSAxNEwyOCAxNEwyOCAxM0wyNyAxM0wyNyAxMkwyOCAxMkwyOCAxMEwyNyAxMEwyNyA5TDI1IDlMMjUgMTBMMjQgMTBMMjQgOUwyMyA5TDIzIDhaTTYgOUw2IDEwTDcgMTBMNyA5Wk0yNSAxMEwyNSAxMUwyNCAxMUwyNCAxNEwyMyAxNEwyMyAxM0wyMiAxM0wyMiAxNEwyMSAxNEwyMSAxM0wxOCAxM0wxOCAxMkwxNyAxMkwxNyAxM0wxNiAxM0wxNiAxNEwxOCAxNEwxOCAxNUwxNiAxNUwxNiAxNkwxNSAxNkwxNSAxN0wxNiAxN0wxNiAxOUwxNyAxOUwxNyAyMEwxNCAyMEwxNCAyMUwxMyAyMUwxMyAyMkwxMiAyMkwxMiAyM0wxMyAyM0wxMyAyNEwxNCAyNEwxNCAyM0wxNSAyM0wxNSAyMkwxNyAyMkwxNyAyMEwxOCAyMEwxOCAxOUwxOSAxOUwxOSAyMEwyMCAyMEwyMCAyMUwxOCAyMUwxOCAyM0wyMCAyM0wyMCAyMkwyMSAyMkwyMSAyMEwyMiAyMEwyMiAxOUwyMyAxOUwyMyAxNUwyNCAxNUwyNCAxNEwyNSAxNEwyNSAxNkwyNyAxNkwyNyAxN0wyNCAxN0wyNCAxOEwyNSAxOEwyNSAxOUwyNCAxOUwyNCAyMUwyNSAyMUwyNSAyMkwyNCAyMkwyNCAyNEwyNSAyNEwyNSAyNUwyNCAyNUwyNCAyNkwyMyAyNkwyMyAyNUwyMiAyNUwyMiAyNEwyMyAyNEwyMyAyM0wyMSAyM0wyMSAyNUwyMiAyNUwyMiAyNkwyMyAyNkwyMyAyN0wyNSAyN0wyNSAyNkwyNiAyNkwyNiAyN0wyNyAyN0wyNyAyNkwyNiAyNkwyNiAyM0wyNyAyM0wyNyAyMkwyNiAyMkwyNiAyMUwyNyAyMUwyNyAyMEwyOSAyMEwyOSAxN0wyOCAxN0wyOCAxNkwyNyAxNkwyNyAxNUwyNiAxNUwyNiAxNEwyNyAxNEwyNyAxM0wyNiAxM0wyNiAxMUwyNyAxMUwyNyAxMFpNMzIgMTBMMzIgMTJMMzQgMTJMMzQgMTFMMzMgMTFMMzMgMTBaTTI1IDEzTDI1IDE0TDI2IDE0TDI2IDEzWk0xOSAxNEwxOSAxNUwyMCAxNUwyMCAxNFpNMjIgMTRMMjIgMTVMMjMgMTVMMjMgMTRaTTMzIDE0TDMzIDE2TDM0IDE2TDM0IDE0Wk00IDE2TDQgMTdMMyAxN0wzIDE4TDUgMThMNSAxN0w3IDE3TDcgMTZaTTE2IDE2TDE2IDE3TDE3IDE3TDE3IDE5TDE4IDE5TDE4IDE3TDE5IDE3TDE5IDE5TDIwIDE5TDIwIDIwTDIxIDIwTDIxIDE5TDIwIDE5TDIwIDE4TDIxIDE4TDIxIDE3TDIyIDE3TDIyIDE2TDIxIDE2TDIxIDE3TDE5IDE3TDE5IDE2TDE4IDE2TDE4IDE3TDE3IDE3TDE3IDE2Wk0yNyAxN0wyNyAxOUwyOCAxOUwyOCAxN1pNMzIgMTdMMzIgMThMMzMgMThMMzMgMTlMMzQgMTlMMzQgMThMMzMgMThMMzMgMTdaTTExIDE5TDExIDIwTDEyIDIwTDEyIDE5Wk0yNSAxOUwyNSAyMEwyNiAyMEwyNiAxOVpNNiAyMUw2IDIyTDcgMjJMNyAyMVpNMTQgMjFMMTQgMjJMMTMgMjJMMTMgMjNMMTQgMjNMMTQgMjJMMTUgMjJMMTUgMjFaTTE2IDIzTDE2IDI0TDE3IDI0TDE3IDI1TDE4IDI1TDE4IDI2TDE5IDI2TDE5IDI1TDIwIDI1TDIwIDI0TDE3IDI0TDE3IDIzWk0wIDI0TDAgMjVMMSAyNUwxIDI2TDAgMjZMMCAyN0wxIDI3TDEgMjhMMCAyOEwwIDI5TDggMjlMOCAzMUw5IDMxTDkgMjlMOCAyOUw4IDI4TDUgMjhMNSAyN0wzIDI3TDMgMjZMMiAyNkwyIDI0Wk0xMyAyNUwxMyAyNkwxNCAyNkwxNCAyNVpNMzUgMjVMMzUgMjdMMzYgMjdMMzYgMjVaTTEgMjZMMSAyN0wyIDI3TDIgMjhMMyAyOEwzIDI3TDIgMjdMMiAyNlpNOCAyNkw4IDI3TDkgMjdMOSAyNlpNMjkgMjZMMjkgMjdMMzIgMjdMMzIgMjZaTTE1IDI4TDE1IDI5TDE0IDI5TDE0IDMwTDE1IDMwTDE1IDI5TDE2IDI5TDE2IDMwTDE3IDMwTDE3IDI5TDE2IDI5TDE2IDI4Wk0xOCAyOUwxOCAzMUwxOSAzMUwxOSAzMkwyMCAzMkwyMCAzMUwxOSAzMUwxOSAyOVpNMjkgMjlMMjkgMzJMMzIgMzJMMzIgMjlaTTMwIDMwTDMwIDMxTDMxIDMxTDMxIDMwWk0xNCAzMkwxNCAzM0wxNSAzM0wxNSAzMlpNMTUgMzRMMTUgMzVMMTYgMzVMMTYgMzRaTTEwIDM1TDEwIDM2TDExIDM2TDExIDM1Wk0zNSAzNkwzNSAzN0wzNyAzN0wzNyAzNlpNMCAwTDAgN0w3IDdMNyAwWk0xIDFMMSA2TDYgNkw2IDFaTTIgMkwyIDVMNSA1TDUgMlpNMzAgMEwzMCA3TDM3IDdMMzcgMFpNMzEgMUwzMSA2TDM2IDZMMzYgMVpNMzIgMkwzMiA1TDM1IDVMMzUgMlpNMCAzMEwwIDM3TDcgMzdMNyAzMFpNMSAzMUwxIDM2TDYgMzZMNiAzMVpNMiAzMkwyIDM1TDUgMzVMNSAzMloiIGZpbGw9IiMwMDAwMDAiLz48L2c+PC9nPjwvc3ZnPgo=	completed	\N	\N	0.00	\N	\N	pra
22	13	\N	POS-2026-00009	mian yonis	\N	910.00	amount	160.00	160.00	16.00	120.00	870.00	cash	191963FCMO1920146478	100	submitted	13	2026-03-19 10:18:18	2026-03-19 10:19:20	24527fd2c067c9f6be67f151348ccddc4cfc127c2a6a77ae1039643e37e977de	data:image/svg+xml;base64,PD94bWwgdmVyc2lvbj0iMS4wIiBlbmNvZGluZz0iVVRGLTgiPz4KPHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZlcnNpb249IjEuMSIgd2lkdGg9IjE1MCIgaGVpZ2h0PSIxNTAiIHZpZXdCb3g9IjAgMCAxNTAgMTUwIj48cmVjdCB4PSIwIiB5PSIwIiB3aWR0aD0iMTUwIiBoZWlnaHQ9IjE1MCIgZmlsbD0iI2ZmZmZmZiIvPjxnIHRyYW5zZm9ybT0ic2NhbGUoMy44NDYpIj48ZyB0cmFuc2Zvcm09InRyYW5zbGF0ZSgxLDEpIj48cGF0aCBmaWxsLXJ1bGU9ImV2ZW5vZGQiIGQ9Ik05IDBMOSAxTDggMUw4IDNMOSAzTDkgNEw4IDRMOCA4TDYgOEw2IDlMNSA5TDUgMTBMNCAxMEw0IDExTDMgMTFMMyAxMkw0IDEyTDQgMTRMMyAxNEwzIDE1TDIgMTVMMiAxNEwxIDE0TDEgMTNMMiAxM0wyIDEyTDAgMTJMMCAxNEwxIDE0TDEgMTVMMCAxNUwwIDE3TDIgMTdMMiAxOEwzIDE4TDMgMTdMNCAxN0w0IDE2TDMgMTZMMyAxNUw0IDE1TDQgMTRMNSAxNEw1IDE1TDggMTVMOCAxNkw1IDE2TDUgMTdMNyAxN0w3IDE4TDYgMThMNiAxOUw4IDE5TDggMjJMNiAyMkw2IDIzTDUgMjNMNSAyMEw0IDIwTDQgMjFMMyAyMUwzIDIwTDIgMjBMMiAxOUwxIDE5TDEgMjBMMiAyMEwyIDIyTDQgMjJMNCAyNEw2IDI0TDYgMjVMNCAyNUw0IDI3TDMgMjdMMyAyNkwyIDI2TDIgMjdMMSAyN0wxIDI2TDAgMjZMMCAyN0wxIDI3TDEgMjhMMCAyOEwwIDI5TDIgMjlMMiAyOEwzIDI4TDMgMjlMNyAyOUw3IDI4TDggMjhMOCAzMUw5IDMxTDkgMzBMMTIgMzBMMTIgMzFMMTQgMzFMMTQgMzJMMTMgMzJMMTMgMzdMMTUgMzdMMTUgMzZMMTYgMzZMMTYgMzdMMTcgMzdMMTcgMzZMMTYgMzZMMTYgMzVMMTkgMzVMMTkgMzdMMjIgMzdMMjIgMzZMMjMgMzZMMjMgMzRMMjEgMzRMMjEgMzNMMjIgMzNMMjIgMzJMMjMgMzJMMjMgMzFMMjIgMzFMMjIgMzBMMjUgMzBMMjUgMzFMMjQgMzFMMjQgMzVMMjUgMzVMMjUgMzdMMjYgMzdMMjYgMzNMMjcgMzNMMjcgMzJMMjUgMzJMMjUgMzFMMjggMzFMMjggMzRMMjcgMzRMMjcgMzdMMzAgMzdMMzAgMzVMMjkgMzVMMjkgMzNMMzIgMzNMMzIgMzVMMzEgMzVMMzEgMzZMMzIgMzZMMzIgMzdMMzQgMzdMMzQgMzZMMzMgMzZMMzMgMzJMMzUgMzJMMzUgMzNMMzQgMzNMMzQgMzVMMzcgMzVMMzcgMzNMMzYgMzNMMzYgMzFMMzcgMzFMMzcgMjlMMzYgMjlMMzYgMzBMMzUgMzBMMzUgMzFMMzQgMzFMMzQgMzBMMzMgMzBMMzMgMjhMMzIgMjhMMzIgMjdMMzUgMjdMMzUgMjhMMzQgMjhMMzQgMjlMMzUgMjlMMzUgMjhMMzYgMjhMMzYgMjdMMzcgMjdMMzcgMjRMMzUgMjRMMzUgMjVMMzQgMjVMMzQgMjZMMzIgMjZMMzIgMjVMMzEgMjVMMzEgMjRMMzMgMjRMMzMgMjJMMzQgMjJMMzQgMjNMMzcgMjNMMzcgMjBMMzYgMjBMMzYgMTlMMzcgMTlMMzcgMTZMMzYgMTZMMzYgMTVMMzcgMTVMMzcgOUwzNiA5TDM2IDhMMzQgOEwzNCA5TDMzIDlMMzMgOEwzMCA4TDMwIDlMMjkgOUwyOSA0TDI4IDRMMjggNUwyNyA1TDI3IDJMMjggMkwyOCAxTDI3IDFMMjcgMkwyNiAyTDI2IDFMMjUgMUwyNSAyTDI0IDJMMjQgMEwyMiAwTDIyIDJMMjQgMkwyNCAzTDIyIDNMMjIgNEwyMSA0TDIxIDNMMjAgM0wyMCAyTDE5IDJMMTkgMUwyMCAxTDIwIDBMMTkgMEwxOSAxTDE3IDFMMTcgMEwxNSAwTDE1IDJMMTQgMkwxNCAzTDE1IDNMMTUgMkwxNiAyTDE2IDRMMTUgNEwxNSA1TDEzIDVMMTMgMkwxMiAyTDEyIDFMMTMgMUwxMyAwTDEyIDBMMTIgMUwxMSAxTDExIDBaTTkgMUw5IDJMMTEgMkwxMSAxWk0xNiAxTDE2IDJMMTcgMkwxNyAxWk0yNSAyTDI1IDNMMjYgM0wyNiAyWk0xMSAzTDExIDRMOSA0TDkgNUwxMCA1TDEwIDZMOSA2TDkgN0wxMCA3TDEwIDhMOCA4TDggOUw2IDlMNiAxMEw1IDEwTDUgMTFMNCAxMUw0IDEyTDUgMTJMNSAxMUw2IDExTDYgMTJMOCAxMkw4IDExTDYgMTFMNiAxMEw5IDEwTDkgMTJMMTAgMTJMMTAgMTNMNSAxM0w1IDE0TDkgMTRMOSAxNkw4IDE2TDggMTdMOSAxN0w5IDE4TDExIDE4TDExIDE5TDkgMTlMOSAyMEwxMCAyMEwxMCAyMUwxMSAyMUwxMSAyMkw4IDIyTDggMjRMNyAyNEw3IDIzTDYgMjNMNiAyNEw3IDI0TDcgMjVMNiAyNUw2IDI2TDUgMjZMNSAyOEw3IDI4TDcgMjdMNiAyN0w2IDI2TDcgMjZMNyAyNUw4IDI1TDggMjRMMTAgMjRMMTAgMjVMOSAyNUw5IDI2TDggMjZMOCAyOEw5IDI4TDkgMjZMMTAgMjZMMTAgMjdMMTEgMjdMMTEgMjZMMTMgMjZMMTMgMjdMMTIgMjdMMTIgMjhMMTAgMjhMMTAgMjlMMTIgMjlMMTIgMzBMMTQgMzBMMTQgMzFMMTggMzFMMTggMzNMMTkgMzNMMTkgMzRMMjAgMzRMMjAgMzVMMjEgMzVMMjEgMzRMMjAgMzRMMjAgMzNMMjEgMzNMMjEgMjlMMTkgMjlMMTkgMjhMMTYgMjhMMTYgMjZMMTcgMjZMMTcgMjdMMjAgMjdMMjAgMjVMMjEgMjVMMjEgMjZMMjIgMjZMMjIgMjhMMjMgMjhMMjMgMjlMMjUgMjlMMjUgMjdMMjYgMjdMMjYgMzBMMjggMzBMMjggMjVMMjcgMjVMMjcgMjRMMjggMjRMMjggMjNMMjkgMjNMMjkgMjVMMzAgMjVMMzAgMjJMMjggMjJMMjggMjFMMzAgMjFMMzAgMjBMMzEgMjBMMzEgMjFMMzIgMjFMMzIgMjJMMzEgMjJMMzEgMjNMMzIgMjNMMzIgMjJMMzMgMjJMMzMgMjFMMzIgMjFMMzIgMjBMMzEgMjBMMzEgMTlMMzMgMTlMMzMgMjBMMzQgMjBMMzQgMjJMMzYgMjJMMzYgMjBMMzQgMjBMMzQgMTlMMzUgMTlMMzUgMThMMzQgMThMMzQgMTZMMzUgMTZMMzUgMTdMMzYgMTdMMzYgMTZMMzUgMTZMMzUgMTVMMzYgMTVMMzYgMTJMMzUgMTJMMzUgMTFMMzYgMTFMMzYgOUwzNSA5TDM1IDExTDM0IDExTDM0IDEwTDMzIDEwTDMzIDlMMzEgOUwzMSAxMEwzMCAxMEwzMCAxMUwyOSAxMUwyOSAxMkwzMCAxMkwzMCAxMUwzMSAxMUwzMSAxMkwzMiAxMkwzMiAxNEwzMCAxNEwzMCAxNUwzMiAxNUwzMiAxNkwzMSAxNkwzMSAxOUwzMCAxOUwzMCAxN0wyOSAxN0wyOSAxNEwyOCAxNEwyOCAxM0wyNyAxM0wyNyAxMkwyOCAxMkwyOCAxMEwyNyAxMEwyNyA5TDI1IDlMMjUgMTBMMjQgMTBMMjQgOUwyMyA5TDIzIDhMMjAgOEwyMCA5TDE5IDlMMTkgN0wyMCA3TDIwIDZMMjEgNkwyMSA3TDIyIDdMMjIgNkwyMyA2TDIzIDdMMjQgN0wyNCA4TDI3IDhMMjcgN0wyOCA3TDI4IDZMMjcgNkwyNyA1TDI1IDVMMjUgNEwyNCA0TDI0IDVMMjMgNUwyMyA0TDIyIDRMMjIgNkwyMSA2TDIxIDRMMTkgNEwxOSAzTDE3IDNMMTcgNEwxNiA0TDE2IDVMMTcgNUwxNyA0TDE5IDRMMTkgNUwyMCA1TDIwIDZMMTkgNkwxOSA3TDE4IDdMMTggNkwxNyA2TDE3IDdMMTggN0wxOCA4TDE0IDhMMTQgNkwxMyA2TDEzIDhMMTIgOEwxMiA1TDExIDVMMTEgNEwxMiA0TDEyIDNaTTI0IDVMMjQgN0wyNSA3TDI1IDVaTTEwIDZMMTAgN0wxMSA3TDExIDZaTTE1IDZMMTUgN0wxNiA3TDE2IDZaTTI2IDZMMjYgN0wyNyA3TDI3IDZaTTAgOEwwIDlMMSA5TDEgMTBMMyAxMEwzIDlMNCA5TDQgOEwzIDhMMyA5TDIgOUwyIDhaTTEwIDhMMTAgOUw5IDlMOSAxMEwxMSAxMEwxMSA5TDEyIDlMMTIgOFpNMTMgOEwxMyAxMEwxMiAxMEwxMiAxMkwxMyAxMkwxMyAxM0wxNCAxM0wxNCAxNEwxMSAxNEwxMSAxNUwxMiAxNUwxMiAxNkwxMyAxNkwxMyAxOEwxMiAxOEwxMiAxN0wxMSAxN0wxMSAxOEwxMiAxOEwxMiAxOUwxMSAxOUwxMSAyMUwxMiAyMUwxMiAyMEwxMyAyMEwxMyAxOUwxNCAxOUwxNCAxOEwxNSAxOEwxNSAxN0wxNiAxN0wxNiAxOUwxNyAxOUwxNyAyMEwxNCAyMEwxNCAyMUwxMyAyMUwxMyAyMkwxNCAyMkwxNCAyM0wxMyAyM0wxMyAyNEwxNCAyNEwxNCAyM0wxNSAyM0wxNSAyMkwxNyAyMkwxNyAyMEwxOCAyMEwxOCAxOUwxOSAxOUwxOSAyMEwyMCAyMEwyMCAyMUwxOCAyMUwxOCAyM0wyMCAyM0wyMCAyMkwyMSAyMkwyMSAyMEwyMiAyMEwyMiAxOUwyMyAxOUwyMyAxNUwyNCAxNUwyNCAxNEwyNSAxNEwyNSAxNkwyNyAxNkwyNyAxN0wyNCAxN0wyNCAxOEwyNSAxOEwyNSAxOUwyNCAxOUwyNCAyMUwyNSAyMUwyNSAyMkwyNCAyMkwyNCAyNEwyNSAyNEwyNSAyNUwyNCAyNUwyNCAyNkwyMyAyNkwyMyAyNUwyMiAyNUwyMiAyNEwyMyAyNEwyMyAyM0wyMSAyM0wyMSAyNUwyMiAyNUwyMiAyNkwyMyAyNkwyMyAyN0wyNSAyN0wyNSAyNkwyNiAyNkwyNiAyN0wyNyAyN0wyNyAyNkwyNiAyNkwyNiAyM0wyNyAyM0wyNyAyMkwyNiAyMkwyNiAyMUwyNyAyMUwyNyAyMEwyOSAyMEwyOSAxN0wyOCAxN0wyOCAxNkwyNyAxNkwyNyAxNUwyNiAxNUwyNiAxNEwyNyAxNEwyNyAxM0wyNiAxM0wyNiAxMUwyNyAxMUwyNyAxMEwyNSAxMEwyNSAxMUwyNCAxMUwyNCAxNEwyMyAxNEwyMyAxM0wyMiAxM0wyMiAxNEwyMSAxNEwyMSAxM0wxOCAxM0wxOCAxMkwyMiAxMkwyMiAxMUwyMSAxMUwyMSAxMEwyMyAxMEwyMyA5TDIwIDlMMjAgMTBMMTggMTBMMTggOUwxNyA5TDE3IDEwTDE2IDEwTDE2IDExTDEzIDExTDEzIDEwTDE1IDEwTDE1IDlMMTQgOUwxNCA4Wk0xNyAxMEwxNyAxMkwxNiAxMkwxNiAxM0wxNSAxM0wxNSAxNEwxNCAxNEwxNCAxNUwxNSAxNUwxNSAxNkwxNCAxNkwxNCAxN0wxNSAxN0wxNSAxNkwxNiAxNkwxNiAxN0wxNyAxN0wxNyAxOUwxOCAxOUwxOCAxN0wxOSAxN0wxOSAxOUwyMCAxOUwyMCAyMEwyMSAyMEwyMSAxOUwyMCAxOUwyMCAxOEwyMSAxOEwyMSAxN0wyMiAxN0wyMiAxNkwyMSAxNkwyMSAxN0wxOSAxN0wxOSAxNkwxOCAxNkwxOCAxN0wxNyAxN0wxNyAxNkwxNiAxNkwxNiAxNUwxOCAxNUwxOCAxNEwxNiAxNEwxNiAxM0wxNyAxM0wxNyAxMkwxOCAxMkwxOCAxMFpNMzIgMTBMMzIgMTJMMzQgMTJMMzQgMTFMMzMgMTFMMzMgMTBaTTEwIDExTDEwIDEyTDExIDEyTDExIDExWk0yNSAxM0wyNSAxNEwyNiAxNEwyNiAxM1pNMTkgMTRMMTkgMTVMMjAgMTVMMjAgMTRaTTIyIDE0TDIyIDE1TDIzIDE1TDIzIDE0Wk0zMyAxNEwzMyAxNkwzNCAxNkwzNCAxNFpNMSAxNUwxIDE2TDIgMTZMMiAxNVpNMjcgMTdMMjcgMTlMMjggMTlMMjggMTdaTTMyIDE3TDMyIDE4TDMzIDE4TDMzIDE5TDM0IDE5TDM0IDE4TDMzIDE4TDMzIDE3Wk0yNSAxOUwyNSAyMEwyNiAyMEwyNiAxOVpNNiAyMEw2IDIxTDcgMjFMNyAyMFpNMCAyMUwwIDIzTDEgMjNMMSAyMVpNMTQgMjFMMTQgMjJMMTUgMjJMMTUgMjFaTTIgMjNMMiAyNEwzIDI0TDMgMjNaTTE2IDIzTDE2IDI0TDE3IDI0TDE3IDI1TDE4IDI1TDE4IDI2TDE5IDI2TDE5IDI1TDIwIDI1TDIwIDI0TDE3IDI0TDE3IDIzWk0wIDI0TDAgMjVMMSAyNUwxIDI0Wk0xMCAyNUwxMCAyNkwxMSAyNkwxMSAyNVpNMTMgMjVMMTMgMjZMMTQgMjZMMTQgMjdMMTMgMjdMMTMgMjlMMTQgMjlMMTQgMzBMMTUgMzBMMTUgMjlMMTYgMjlMMTYgMzBMMTcgMzBMMTcgMjlMMTYgMjlMMTYgMjhMMTUgMjhMMTUgMjZMMTQgMjZMMTQgMjVaTTM1IDI1TDM1IDI3TDM2IDI3TDM2IDI1Wk0yOSAyNkwyOSAyN0wzMiAyN0wzMiAyNlpNMTQgMjhMMTQgMjlMMTUgMjlMMTUgMjhaTTE4IDI5TDE4IDMxTDE5IDMxTDE5IDMyTDIwIDMyTDIwIDMxTDE5IDMxTDE5IDI5Wk0yOSAyOUwyOSAzMkwzMiAzMkwzMiAyOVpNMzAgMzBMMzAgMzFMMzEgMzFMMzEgMzBaTTkgMzJMOSAzM0w4IDMzTDggMzRMOSAzNEw5IDM1TDggMzVMOCAzN0wxMCAzN0wxMCAzNkw5IDM2TDkgMzVMMTEgMzVMMTEgMzRMOSAzNEw5IDMzTDEwIDMzTDEwIDMyWk0xMSAzMkwxMSAzM0wxMiAzM0wxMiAzMlpNMTQgMzJMMTQgMzNMMTUgMzNMMTUgMzJaTTE1IDM0TDE1IDM1TDE0IDM1TDE0IDM2TDE1IDM2TDE1IDM1TDE2IDM1TDE2IDM0Wk0xMSAzNkwxMSAzN0wxMiAzN0wxMiAzNlpNMzUgMzZMMzUgMzdMMzcgMzdMMzcgMzZaTTAgMEwwIDdMNyA3TDcgMFpNMSAxTDEgNkw2IDZMNiAxWk0yIDJMMiA1TDUgNUw1IDJaTTMwIDBMMzAgN0wzNyA3TDM3IDBaTTMxIDFMMzEgNkwzNiA2TDM2IDFaTTMyIDJMMzIgNUwzNSA1TDM1IDJaTTAgMzBMMCAzN0w3IDM3TDcgMzBaTTEgMzFMMSAzNkw2IDM2TDYgMzFaTTIgMzJMMiAzNUw1IDM1TDUgMzJaIiBmaWxsPSIjMDAwMDAwIi8+PC9nPjwvZz48L3N2Zz4K	completed	\N	\N	0.00	\N	\N	pra
18	13	\N	POS-2026-00005	\N	\N	750.00	percentage	0.00	0.00	16.00	120.00	870.00	cash	191963FCMN5445803834	100	submitted	13	2026-03-09 13:17:50	2026-03-19 09:54:46	7e736bc42a2e4f4865f4ed9c311e4a9189fe7d63feaa02f6ab22875d6a9c9ab6	data:image/svg+xml;base64,PD94bWwgdmVyc2lvbj0iMS4wIiBlbmNvZGluZz0iVVRGLTgiPz4KPHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZlcnNpb249IjEuMSIgd2lkdGg9IjE1MCIgaGVpZ2h0PSIxNTAiIHZpZXdCb3g9IjAgMCAxNTAgMTUwIj48cmVjdCB4PSIwIiB5PSIwIiB3aWR0aD0iMTUwIiBoZWlnaHQ9IjE1MCIgZmlsbD0iI2ZmZmZmZiIvPjxnIHRyYW5zZm9ybT0ic2NhbGUoMy44NDYpIj48ZyB0cmFuc2Zvcm09InRyYW5zbGF0ZSgxLDEpIj48cGF0aCBmaWxsLXJ1bGU9ImV2ZW5vZGQiIGQ9Ik0xMiAwTDEyIDJMMTMgMkwxMyAwWk0xNCAwTDE0IDFMMTUgMUwxNSAyTDE0IDJMMTQgM0wxMyAzTDEzIDRMMTEgNEwxMSAzTDggM0w4IDRMOSA0TDkgNUw4IDVMOCA3TDkgN0w5IDhMNiA4TDYgOUw3IDlMNyAxMEw1IDEwTDUgMTFMNCAxMUw0IDEyTDYgMTJMNiAxM0w4IDEzTDggMTJMOSAxMkw5IDExTDggMTFMOCA5TDEwIDlMMTAgMTRMOSAxNEw5IDE1TDEwIDE1TDEwIDE0TDEzIDE0TDEzIDE1TDExIDE1TDExIDE2TDEzIDE2TDEzIDE1TDE0IDE1TDE0IDE3TDE1IDE3TDE1IDE1TDE2IDE1TDE2IDE2TDE3IDE2TDE3IDE3TDE2IDE3TDE2IDE4TDEzIDE4TDEzIDE3TDExIDE3TDExIDE4TDEwIDE4TDEwIDE3TDcgMTdMNyAxNkw4IDE2TDggMTVMNyAxNUw3IDE0TDUgMTRMNSAxM0wzIDEzTDMgMTJMMiAxMkwyIDEwTDQgMTBMNCA5TDUgOUw1IDhMMCA4TDAgMTFMMSAxMUwxIDEyTDIgMTJMMiAxNUwzIDE1TDMgMTRMNCAxNEw0IDE1TDUgMTVMNSAxNkw0IDE2TDQgMTdMNyAxN0w3IDE4TDYgMThMNiAxOUw3IDE5TDcgMThMMTAgMThMMTAgMTlMMTEgMTlMMTEgMThMMTIgMThMMTIgMjBMMTMgMjBMMTMgMTlMMTYgMTlMMTYgMjBMMTcgMjBMMTcgMjFMMTUgMjFMMTUgMjBMMTQgMjBMMTQgMjFMMTUgMjFMMTUgMjNMMTQgMjNMMTQgMjJMMTMgMjJMMTMgMjFMMTEgMjFMMTEgMjBMOSAyMEw5IDIxTDggMjFMOCAyMEw2IDIwTDYgMjFMNSAyMUw1IDIwTDQgMjBMNCAxOEwzIDE4TDMgMTdMMSAxN0wxIDE1TDAgMTVMMCAxN0wxIDE3TDEgMThMMyAxOEwzIDIwTDQgMjBMNCAyMUw1IDIxTDUgMjRMMyAyNEwzIDIzTDIgMjNMMiAyMkwzIDIyTDMgMjFMMSAyMUwxIDIwTDIgMjBMMiAxOUwxIDE5TDEgMjBMMCAyMEwwIDIxTDEgMjFMMSAyM0wwIDIzTDAgMjlMMSAyOUwxIDI1TDIgMjVMMiAyNEwzIDI0TDMgMjVMNSAyNUw1IDI2TDYgMjZMNiAyN0w3IDI3TDcgMjZMOCAyNkw4IDI4TDUgMjhMNSAyN0w0IDI3TDQgMjZMMiAyNkwyIDI5TDMgMjlMMyAyOEw1IDI4TDUgMjlMOCAyOUw4IDMxTDEwIDMxTDEwIDI5TDExIDI5TDExIDMwTDEyIDMwTDEyIDMxTDE1IDMxTDE1IDMyTDE2IDMyTDE2IDMxTDE3IDMxTDE3IDI5TDE2IDI5TDE2IDMwTDE0IDMwTDE0IDI5TDE1IDI5TDE1IDI3TDE2IDI3TDE2IDI4TDE3IDI4TDE3IDI3TDE4IDI3TDE4IDI1TDIwIDI1TDIwIDI4TDIyIDI4TDIyIDI5TDE5IDI5TDE5IDMwTDE4IDMwTDE4IDMyTDIwIDMyTDIwIDMxTDIxIDMxTDIxIDMyTDIyIDMyTDIyIDMzTDIzIDMzTDIzIDM0TDIyIDM0TDIyIDM1TDIxIDM1TDIxIDMzTDE5IDMzTDE5IDM0TDE3IDM0TDE3IDMzTDE0IDMzTDE0IDMyTDEzIDMyTDEzIDMzTDExIDMzTDExIDMyTDggMzJMOCAzN0w5IDM3TDkgMzZMMTAgMzZMMTAgMzVMOSAzNUw5IDM0TDEwIDM0TDEwIDMzTDExIDMzTDExIDM0TDEyIDM0TDEyIDM1TDEzIDM1TDEzIDMzTDE0IDMzTDE0IDM2TDEzIDM2TDEzIDM3TDE0IDM3TDE0IDM2TDE1IDM2TDE1IDM3TDE2IDM3TDE2IDM1TDE4IDM1TDE4IDM2TDE5IDM2TDE5IDM3TDIwIDM3TDIwIDM2TDE5IDM2TDE5IDM0TDIwIDM0TDIwIDM1TDIxIDM1TDIxIDM2TDIyIDM2TDIyIDM3TDIzIDM3TDIzIDM0TDI0IDM0TDI0IDM2TDI1IDM2TDI1IDM3TDI3IDM3TDI3IDM2TDI5IDM2TDI5IDM3TDMwIDM3TDMwIDM2TDMxIDM2TDMxIDM1TDMyIDM1TDMyIDM0TDMzIDM0TDMzIDM1TDM2IDM1TDM2IDM2TDM0IDM2TDM0IDM3TDM3IDM3TDM3IDM0TDM1IDM0TDM1IDMyTDM0IDMyTDM0IDMxTDM2IDMxTDM2IDMyTDM3IDMyTDM3IDMwTDM0IDMwTDM0IDMxTDMzIDMxTDMzIDI5TDM1IDI5TDM1IDI4TDI5IDI4TDI5IDI3TDMwIDI3TDMwIDI2TDI5IDI2TDI5IDI3TDI4IDI3TDI4IDI2TDI2IDI2TDI2IDI1TDI3IDI1TDI3IDIzTDI4IDIzTDI4IDI0TDI5IDI0TDI5IDIzTDI4IDIzTDI4IDIyTDI5IDIyTDI5IDIxTDMxIDIxTDMxIDIwTDMyIDIwTDMyIDIxTDMzIDIxTDMzIDIzTDM2IDIzTDM2IDI0TDMyIDI0TDMyIDI1TDMxIDI1TDMxIDI0TDMwIDI0TDMwIDI1TDMxIDI1TDMxIDI2TDMyIDI2TDMyIDI3TDMzIDI3TDMzIDI2TDMyIDI2TDMyIDI1TDM1IDI1TDM1IDI3TDM2IDI3TDM2IDI4TDM3IDI4TDM3IDI2TDM2IDI2TDM2IDI1TDM3IDI1TDM3IDIyTDM2IDIyTDM2IDIxTDM3IDIxTDM3IDE4TDM2IDE4TDM2IDE3TDM3IDE3TDM3IDE0TDM2IDE0TDM2IDEzTDM3IDEzTDM3IDEyTDM2IDEyTDM2IDExTDM3IDExTDM3IDEwTDM2IDEwTDM2IDhMMzUgOEwzNSA5TDM0IDlMMzQgOEwzMyA4TDMzIDEyTDMyIDEyTDMyIDhMMzEgOEwzMSAxMkwyOSAxMkwyOSAxMUwzMCAxMUwzMCAxMEwyOSAxMEwyOSA5TDMwIDlMMzAgOEwyOSA4TDI5IDlMMjcgOUwyNyA4TDI4IDhMMjggN0wyOSA3TDI5IDRMMjcgNEwyNyAzTDI5IDNMMjkgMEwyNiAwTDI2IDFMMjQgMUwyNCAwTDIzIDBMMjMgMUwyNCAxTDI0IDJMMjEgMkwyMSAzTDIwIDNMMjAgMkwxOCAyTDE4IDNMMTYgM0wxNiAyTDE3IDJMMTcgMUwxNSAxTDE1IDBaTTE5IDBMMTkgMUwyMiAxTDIyIDBaTTggMUw4IDJMOSAyTDkgMVpNMTAgMUwxMCAyTDExIDJMMTEgMVpNMjYgMUwyNiAyTDI1IDJMMjUgM0wyMyAzTDIzIDRMMjIgNEwyMiAzTDIxIDNMMjEgNUwyMiA1TDIyIDZMMjEgNkwyMSA3TDIyIDdMMjIgOEwyMyA4TDIzIDlMMjQgOUwyNCAxMEwyMiAxMEwyMiAxMkwyMyAxMkwyMyAxM0wyMSAxM0wyMSAxNUwyMiAxNUwyMiAxNEwyMyAxNEwyMyAxNUwyNSAxNUwyNSAxNkwyMyAxNkwyMyAxOEwxOSAxOEwxOSAxN0wyMSAxN0wyMSAxNkwyMCAxNkwyMCAxNEwxOSAxNEwxOSAxM0wyMCAxM0wyMCAxMkwyMSAxMkwyMSAxMEwxOSAxMEwxOSAxMUwxOCAxMUwxOCA5TDE5IDlMMTkgOEwxOCA4TDE4IDZMMTkgNkwxOSA3TDIwIDdMMjAgNUwxOCA1TDE4IDZMMTcgNkwxNyA3TDE2IDdMMTYgNkwxNSA2TDE1IDVMMTcgNUwxNyA0TDE2IDRMMTYgM0wxNCAzTDE0IDRMMTUgNEwxNSA1TDExIDVMMTEgNEwxMCA0TDEwIDZMOSA2TDkgN0wxMCA3TDEwIDZMMTEgNkwxMSA3TDEyIDdMMTIgNkwxMyA2TDEzIDhMMTAgOEwxMCA5TDEzIDlMMTMgMTBMMTQgMTBMMTQgOUwxNSA5TDE1IDEwTDE3IDEwTDE3IDEyTDE4IDEyTDE4IDEzTDE2IDEzTDE2IDEyTDE1IDEyTDE1IDExTDEzIDExTDEzIDEyTDEyIDEyTDEyIDEwTDExIDEwTDExIDEyTDEyIDEyTDEyIDEzTDEzIDEzTDEzIDE0TDE0IDE0TDE0IDE1TDE1IDE1TDE1IDE0TDE5IDE0TDE5IDE1TDE3IDE1TDE3IDE2TDE4IDE2TDE4IDE3TDE3IDE3TDE3IDE4TDE2IDE4TDE2IDE5TDE3IDE5TDE3IDIwTDE4IDIwTDE4IDIyTDE2IDIyTDE2IDIzTDE1IDIzTDE1IDI0TDE2IDI0TDE2IDIzTDE3IDIzTDE3IDI0TDE4IDI0TDE4IDIyTDE5IDIyTDE5IDIzTDIwIDIzTDIwIDIwTDIxIDIwTDIxIDE5TDI0IDE5TDI0IDIwTDI2IDIwTDI2IDE5TDI3IDE5TDI3IDE4TDI4IDE4TDI4IDIwTDI5IDIwTDI5IDE5TDMwIDE5TDMwIDE4TDMxIDE4TDMxIDE5TDMyIDE5TDMyIDE4TDMzIDE4TDMzIDIxTDM0IDIxTDM0IDIyTDM1IDIyTDM1IDIxTDM0IDIxTDM0IDIwTDM2IDIwTDM2IDE5TDM0IDE5TDM0IDE3TDMyIDE3TDMyIDE4TDMxIDE4TDMxIDE3TDI5IDE3TDI5IDE2TDMzIDE2TDMzIDE1TDM0IDE1TDM0IDE2TDM1IDE2TDM1IDE3TDM2IDE3TDM2IDE1TDM0IDE1TDM0IDEzTDMyIDEzTDMyIDE0TDMxIDE0TDMxIDEzTDI5IDEzTDI5IDEyTDI4IDEyTDI4IDExTDI3IDExTDI3IDlMMjYgOUwyNiAxMEwyNSAxMEwyNSA5TDI0IDlMMjQgNkwyNSA2TDI1IDhMMjcgOEwyNyA3TDI4IDdMMjggNkwyNyA2TDI3IDdMMjYgN0wyNiA2TDI1IDZMMjUgNUwyNyA1TDI3IDRMMjYgNEwyNiAyTDI3IDJMMjcgMVpNMTkgM0wxOSA0TDIwIDRMMjAgM1pNMjMgNEwyMyA1TDI0IDVMMjQgNFpNMTQgNkwxNCA4TDEzIDhMMTMgOUwxNCA5TDE0IDhMMTUgOEwxNSA5TDE4IDlMMTggOEwxNiA4TDE2IDdMMTUgN0wxNSA2Wk0yMiA2TDIyIDdMMjMgN0wyMyA2Wk0yMCA4TDIwIDlMMjEgOUwyMSA4Wk0zNSAxMEwzNSAxMUwzNCAxMUwzNCAxMkwzNSAxMkwzNSAxM0wzNiAxM0wzNiAxMkwzNSAxMkwzNSAxMUwzNiAxMUwzNiAxMFpNNiAxMUw2IDEyTDggMTJMOCAxMVpNMjYgMTFMMjYgMTJMMjQgMTJMMjQgMTNMMjMgMTNMMjMgMTRMMjQgMTRMMjQgMTNMMjYgMTNMMjYgMTRMMjUgMTRMMjUgMTVMMjYgMTVMMjYgMTZMMjUgMTZMMjUgMTdMMjcgMTdMMjcgMTVMMjkgMTVMMjkgMTRMMjcgMTRMMjcgMTFaTTEzIDEyTDEzIDEzTDE0IDEzTDE0IDE0TDE1IDE0TDE1IDEzTDE0IDEzTDE0IDEyWk0wIDEzTDAgMTRMMSAxNEwxIDEzWk0yNiAxNEwyNiAxNUwyNyAxNUwyNyAxNFpNNiAxNUw2IDE2TDcgMTZMNyAxNVpNMjggMTdMMjggMThMMjkgMThMMjkgMTdaTTE4IDE4TDE4IDIwTDIwIDIwTDIwIDE5TDE5IDE5TDE5IDE4Wk0yNSAxOEwyNSAxOUwyNiAxOUwyNiAxOFpNNiAyMUw2IDIyTDcgMjJMNyAyM0w2IDIzTDYgMjRMNSAyNEw1IDI1TDYgMjVMNiAyNkw3IDI2TDcgMjVMOCAyNUw4IDI0TDkgMjRMOSAyN0wxMCAyN0wxMCAyNEwxMiAyNEwxMiAyNUwxNCAyNUwxNCAyNkwxMSAyNkwxMSAyOUwxNCAyOUwxNCAyNkwxNiAyNkwxNiAyN0wxNyAyN0wxNyAyNkwxNiAyNkwxNiAyNUwxNCAyNUwxNCAyM0wxMyAyM0wxMyAyNEwxMiAyNEwxMiAyMkwxMCAyMkwxMCAyNEw5IDI0TDkgMjNMOCAyM0w4IDIxWk0yMSAyMUwyMSAyMkwyMiAyMkwyMiAyM0wyMyAyM0wyMyAyNUwyMiAyNUwyMiAyNEwyMSAyNEwyMSAyN0wyMiAyN0wyMiAyOEwyMyAyOEwyMyAyOUwyNCAyOUwyNCAzMEwyMyAzMEwyMyAzMUwyMiAzMUwyMiAzMEwyMSAzMEwyMSAzMUwyMiAzMUwyMiAzMkwyMyAzMkwyMyAzMUwyNCAzMUwyNCAzMkwyOCAzMkwyOCAyOUwyNyAyOUwyNyAzMEwyNiAzMEwyNiAyOEwyOCAyOEwyOCAyN0wyNiAyN0wyNiAyNkwyNSAyNkwyNSAyNEwyNiAyNEwyNiAyM0wyNyAyM0wyNyAyMkwyOCAyMkwyOCAyMUwyNSAyMUwyNSAyM0wyNCAyM0wyNCAyMkwyMyAyMkwyMyAyMVpNMzAgMjJMMzAgMjNMMzIgMjNMMzIgMjJaTTcgMjNMNyAyNEw2IDI0TDYgMjVMNyAyNUw3IDI0TDggMjRMOCAyM1pNMjMgMjVMMjMgMjZMMjIgMjZMMjIgMjdMMjMgMjdMMjMgMjhMMjUgMjhMMjUgMjZMMjQgMjZMMjQgMjVaTTIzIDI2TDIzIDI3TDI0IDI3TDI0IDI2Wk04IDI4TDggMjlMMTAgMjlMMTAgMjhaTTI5IDI5TDI5IDMyTDMyIDMyTDMyIDI5Wk0xOSAzMEwxOSAzMUwyMCAzMUwyMCAzMFpNMjUgMzBMMjUgMzFMMjYgMzFMMjYgMzBaTTMwIDMwTDMwIDMxTDMxIDMxTDMxIDMwWk0yNCAzM0wyNCAzNEwyNSAzNEwyNSAzNkwyNiAzNkwyNiAzNEwyNyAzNEwyNyAzNUwyOCAzNUwyOCAzM1pNMzMgMzNMMzMgMzRMMzQgMzRMMzQgMzNaTTI5IDM0TDI5IDM1TDMxIDM1TDMxIDM0Wk0wIDBMMCA3TDcgN0w3IDBaTTEgMUwxIDZMNiA2TDYgMVpNMiAyTDIgNUw1IDVMNSAyWk0zMCAwTDMwIDdMMzcgN0wzNyAwWk0zMSAxTDMxIDZMMzYgNkwzNiAxWk0zMiAyTDMyIDVMMzUgNUwzNSAyWk0wIDMwTDAgMzdMNyAzN0w3IDMwWk0xIDMxTDEgMzZMNiAzNkw2IDMxWk0yIDMyTDIgMzVMNSAzNUw1IDMyWiIgZmlsbD0iIzAwMDAwMCIvPjwvZz48L2c+PC9zdmc+Cg==	completed	\N	\N	0.00	\N	\N	pra
34	13	\N	POS-2026-00018	ammad	\N	500.00	percentage	0.00	0.00	16.00	80.00	580.00	cash	191963FCRP5423712689	100	submitted	14	2026-03-24 16:54:23	2026-03-24 16:54:24	ca6612d7c03cadde91ebc497466e5321b0866eeb84e9281bfd49517ed94f6813	data:image/svg+xml;base64,PD94bWwgdmVyc2lvbj0iMS4wIiBlbmNvZGluZz0iVVRGLTgiPz4KPHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZlcnNpb249IjEuMSIgd2lkdGg9IjE1MCIgaGVpZ2h0PSIxNTAiIHZpZXdCb3g9IjAgMCAxNTAgMTUwIj48cmVjdCB4PSIwIiB5PSIwIiB3aWR0aD0iMTUwIiBoZWlnaHQ9IjE1MCIgZmlsbD0iI2ZmZmZmZiIvPjxnIHRyYW5zZm9ybT0ic2NhbGUoMy44NDYpIj48ZyB0cmFuc2Zvcm09InRyYW5zbGF0ZSgxLDEpIj48cGF0aCBmaWxsLXJ1bGU9ImV2ZW5vZGQiIGQ9Ik0xMiAwTDEyIDFMMTMgMUwxMyAwWk0xNCAwTDE0IDFMMTUgMUwxNSAyTDE0IDJMMTQgM0wxMyAzTDEzIDJMMTIgMkwxMiAzTDggM0w4IDRMOSA0TDkgNUw4IDVMOCA3TDkgN0w5IDhMNiA4TDYgOUw3IDlMNyAxMEw2IDEwTDYgMTFMNSAxMUw1IDEyTDQgMTJMNCAxMUwzIDExTDMgMTBMMiAxMEwyIDlMNCA5TDQgMTBMNSAxMEw1IDhMMCA4TDAgOUwxIDlMMSAxMkwwIDEyTDAgMTNMMSAxM0wxIDEyTDIgMTJMMiAxNUwxIDE1TDEgMTdMMCAxN0wwIDE4TDIgMThMMiAxN0wzIDE3TDMgMTZMNCAxNkw0IDE3TDUgMTdMNSAxOEw0IDE4TDQgMTlMMCAxOUwwIDIwTDQgMjBMNCAyMUwyIDIxTDIgMjRMMyAyNEwzIDI1TDQgMjVMNCAyNkw1IDI2TDUgMjRMNiAyNEw2IDI1TDcgMjVMNyAyNkw2IDI2TDYgMjdMNyAyN0w3IDI2TDggMjZMOCAyN0w5IDI3TDkgMjhMOCAyOEw4IDMxTDkgMzFMOSAzMEwxMCAzMEwxMCAyOUw5IDI5TDkgMjhMMTAgMjhMMTAgMjZMOCAyNkw4IDI1TDEwIDI1TDEwIDI0TDggMjRMOCAyNUw3IDI1TDcgMjRMNiAyNEw2IDIzTDggMjNMOCAyMUw5IDIxTDkgMjNMMTAgMjNMMTAgMjBMMTEgMjBMMTEgMThMMTMgMThMMTMgMTlMMTIgMTlMMTIgMjBMMTMgMjBMMTMgMTlMMTYgMTlMMTYgMjBMMTcgMjBMMTcgMjFMMTUgMjFMMTUgMjBMMTQgMjBMMTQgMjFMMTUgMjFMMTUgMjNMMTQgMjNMMTQgMjJMMTMgMjJMMTMgMjFMMTEgMjFMMTEgMjJMMTMgMjJMMTMgMjNMMTQgMjNMMTQgMjZMMTMgMjZMMTMgMjVMMTEgMjVMMTEgMjZMMTMgMjZMMTMgMjdMMTEgMjdMMTEgMjhMMTMgMjhMMTMgMjdMMTQgMjdMMTQgMjZMMTUgMjZMMTUgMjdMMTYgMjdMMTYgMjhMMTcgMjhMMTcgMjdMMTggMjdMMTggMjVMMjAgMjVMMjAgMjhMMjIgMjhMMjIgMjlMMTkgMjlMMTkgMzBMMTggMzBMMTggMzJMMjAgMzJMMjAgMzFMMjEgMzFMMjEgMzJMMjIgMzJMMjIgMzNMMjMgMzNMMjMgMzRMMjIgMzRMMjIgMzVMMjEgMzVMMjEgMzNMMTkgMzNMMTkgMzRMMTcgMzRMMTcgMzNMMTQgMzNMMTQgMzJMMTMgMzJMMTMgMjlMMTEgMjlMMTEgMzBMMTIgMzBMMTIgMzFMMTEgMzFMMTEgMzJMMTMgMzJMMTMgMzNMMTAgMzNMMTAgMzRMMTIgMzRMMTIgMzVMMTMgMzVMMTMgMzNMMTQgMzNMMTQgMzZMMTMgMzZMMTMgMzdMMTQgMzdMMTQgMzZMMTUgMzZMMTUgMzdMMTYgMzdMMTYgMzVMMTggMzVMMTggMzZMMTkgMzZMMTkgMzdMMjAgMzdMMjAgMzZMMTkgMzZMMTkgMzRMMjAgMzRMMjAgMzVMMjEgMzVMMjEgMzZMMjIgMzZMMjIgMzdMMjMgMzdMMjMgMzRMMjQgMzRMMjQgMzZMMjUgMzZMMjUgMzdMMjcgMzdMMjcgMzZMMjkgMzZMMjkgMzdMMzAgMzdMMzAgMzZMMzEgMzZMMzEgMzVMMzIgMzVMMzIgMzRMMzMgMzRMMzMgMzVMMzYgMzVMMzYgMzZMMzQgMzZMMzQgMzdMMzcgMzdMMzcgMzRMMzUgMzRMMzUgMzJMMzQgMzJMMzQgMzFMMzYgMzFMMzYgMzJMMzcgMzJMMzcgMzBMMzQgMzBMMzQgMzFMMzMgMzFMMzMgMjlMMzUgMjlMMzUgMjhMMjkgMjhMMjkgMjdMMzAgMjdMMzAgMjZMMjkgMjZMMjkgMjdMMjggMjdMMjggMjZMMjYgMjZMMjYgMjVMMjcgMjVMMjcgMjNMMjggMjNMMjggMjRMMjkgMjRMMjkgMjNMMjggMjNMMjggMjJMMjkgMjJMMjkgMjFMMzEgMjFMMzEgMjBMMzIgMjBMMzIgMjFMMzMgMjFMMzMgMjNMMzYgMjNMMzYgMjRMMzIgMjRMMzIgMjVMMzEgMjVMMzEgMjRMMzAgMjRMMzAgMjVMMzEgMjVMMzEgMjZMMzIgMjZMMzIgMjdMMzMgMjdMMzMgMjZMMzIgMjZMMzIgMjVMMzUgMjVMMzUgMjdMMzYgMjdMMzYgMjhMMzcgMjhMMzcgMjZMMzYgMjZMMzYgMjVMMzcgMjVMMzcgMjJMMzYgMjJMMzYgMjFMMzcgMjFMMzcgMThMMzYgMThMMzYgMTdMMzcgMTdMMzcgMTRMMzYgMTRMMzYgMTNMMzcgMTNMMzcgMTJMMzYgMTJMMzYgMTFMMzcgMTFMMzcgMTBMMzYgMTBMMzYgOEwzNSA4TDM1IDlMMzQgOUwzNCA4TDMzIDhMMzMgMTJMMzIgMTJMMzIgOEwzMSA4TDMxIDEyTDI5IDEyTDI5IDExTDMwIDExTDMwIDEwTDI5IDEwTDI5IDlMMzAgOUwzMCA4TDI5IDhMMjkgOUwyNyA5TDI3IDhMMjggOEwyOCA3TDI5IDdMMjkgNEwyNyA0TDI3IDNMMjkgM0wyOSAwTDI2IDBMMjYgMUwyNCAxTDI0IDBMMjMgMEwyMyAxTDI0IDFMMjQgMkwyMSAyTDIxIDNMMjAgM0wyMCAyTDE4IDJMMTggM0wxNiAzTDE2IDJMMTcgMkwxNyAxTDE1IDFMMTUgMFpNMTkgMEwxOSAxTDIyIDFMMjIgMFpNOCAxTDggMkw5IDJMOSAxWk0xMCAxTDEwIDJMMTEgMkwxMSAxWk0yNiAxTDI2IDJMMjUgMkwyNSAzTDIzIDNMMjMgNEwyMiA0TDIyIDNMMjEgM0wyMSA1TDIyIDVMMjIgNkwyMSA2TDIxIDdMMjIgN0wyMiA4TDIzIDhMMjMgOUwyNCA5TDI0IDEwTDIyIDEwTDIyIDEyTDIzIDEyTDIzIDEzTDIxIDEzTDIxIDE1TDIyIDE1TDIyIDE0TDIzIDE0TDIzIDE1TDI1IDE1TDI1IDE2TDIzIDE2TDIzIDE4TDE5IDE4TDE5IDE3TDIxIDE3TDIxIDE2TDIwIDE2TDIwIDE0TDE5IDE0TDE5IDEzTDIwIDEzTDIwIDEyTDIxIDEyTDIxIDEwTDE5IDEwTDE5IDExTDE4IDExTDE4IDlMMTkgOUwxOSA4TDE4IDhMMTggNkwxOSA2TDE5IDdMMjAgN0wyMCA1TDE4IDVMMTggNkwxNyA2TDE3IDdMMTYgN0wxNiA2TDE1IDZMMTUgNUwxNyA1TDE3IDRMMTYgNEwxNiAzTDE0IDNMMTQgNEwxNSA0TDE1IDVMMTMgNUwxMyA4TDEyIDhMMTIgNUwxMSA1TDExIDRMMTAgNEwxMCA2TDkgNkw5IDdMMTAgN0wxMCA2TDExIDZMMTEgOEwxMCA4TDEwIDlMMTEgOUwxMSA4TDEyIDhMMTIgOUwxMyA5TDEzIDEwTDE0IDEwTDE0IDlMMTUgOUwxNSAxMEwxNyAxMEwxNyAxMkwxOCAxMkwxOCAxM0wxNiAxM0wxNiAxMkwxNSAxMkwxNSAxMUwxMiAxMUwxMiAxMEw5IDEwTDkgOUw4IDlMOCAxMEw3IDEwTDcgMTFMNiAxMUw2IDEyTDUgMTJMNSAxNEwzIDE0TDMgMTVMNiAxNUw2IDE2TDExIDE2TDExIDE3TDEwIDE3TDEwIDE4TDExIDE4TDExIDE3TDEzIDE3TDEzIDE4TDE2IDE4TDE2IDE5TDE3IDE5TDE3IDIwTDE4IDIwTDE4IDIyTDE2IDIyTDE2IDIzTDE1IDIzTDE1IDI0TDE2IDI0TDE2IDIzTDE3IDIzTDE3IDI0TDE4IDI0TDE4IDIyTDE5IDIyTDE5IDIzTDIwIDIzTDIwIDIwTDIxIDIwTDIxIDE5TDI0IDE5TDI0IDIwTDI2IDIwTDI2IDE5TDI3IDE5TDI3IDE4TDI4IDE4TDI4IDIwTDI5IDIwTDI5IDE5TDMwIDE5TDMwIDE4TDMxIDE4TDMxIDE5TDMyIDE5TDMyIDE4TDMzIDE4TDMzIDIxTDM0IDIxTDM0IDIyTDM1IDIyTDM1IDIxTDM0IDIxTDM0IDIwTDM2IDIwTDM2IDE5TDM0IDE5TDM0IDE3TDMyIDE3TDMyIDE4TDMxIDE4TDMxIDE3TDI5IDE3TDI5IDE2TDMzIDE2TDMzIDE1TDM0IDE1TDM0IDE2TDM1IDE2TDM1IDE3TDM2IDE3TDM2IDE1TDM0IDE1TDM0IDEzTDMyIDEzTDMyIDE0TDMxIDE0TDMxIDEzTDI5IDEzTDI5IDEyTDI4IDEyTDI4IDExTDI3IDExTDI3IDlMMjYgOUwyNiAxMEwyNSAxMEwyNSA5TDI0IDlMMjQgNkwyNSA2TDI1IDhMMjcgOEwyNyA3TDI4IDdMMjggNkwyNyA2TDI3IDdMMjYgN0wyNiA2TDI1IDZMMjUgNUwyNyA1TDI3IDRMMjYgNEwyNiAyTDI3IDJMMjcgMVpNMTIgM0wxMiA0TDEzIDRMMTMgM1pNMTkgM0wxOSA0TDIwIDRMMjAgM1pNMjMgNEwyMyA1TDI0IDVMMjQgNFpNMTQgNkwxNCA4TDEzIDhMMTMgOUwxNCA5TDE0IDhMMTUgOEwxNSA5TDE4IDlMMTggOEwxNiA4TDE2IDdMMTUgN0wxNSA2Wk0yMiA2TDIyIDdMMjMgN0wyMyA2Wk0yMCA4TDIwIDlMMjEgOUwyMSA4Wk04IDEwTDggMTFMNyAxMUw3IDEyTDYgMTJMNiAxM0wxMCAxM0wxMCAxNEw2IDE0TDYgMTVMMTAgMTVMMTAgMTRMMTIgMTRMMTIgMTVMMTEgMTVMMTEgMTZMMTMgMTZMMTMgMTVMMTQgMTVMMTQgMTdMMTUgMTdMMTUgMTVMMTYgMTVMMTYgMTZMMTcgMTZMMTcgMTdMMTYgMTdMMTYgMThMMTcgMThMMTcgMTdMMTggMTdMMTggMTZMMTcgMTZMMTcgMTVMMTkgMTVMMTkgMTRMMTUgMTRMMTUgMTNMMTQgMTNMMTQgMTJMMTMgMTJMMTMgMTNMMTEgMTNMMTEgMTJMMTIgMTJMMTIgMTFMMTEgMTFMMTEgMTJMMTAgMTJMMTAgMTFMOSAxMUw5IDEwWk0zNSAxMEwzNSAxMUwzNCAxMUwzNCAxMkwzNSAxMkwzNSAxM0wzNiAxM0wzNiAxMkwzNSAxMkwzNSAxMUwzNiAxMUwzNiAxMFpNMiAxMUwyIDEyTDMgMTJMMyAxM0w0IDEzTDQgMTJMMyAxMkwzIDExWk0yNiAxMUwyNiAxMkwyNCAxMkwyNCAxM0wyMyAxM0wyMyAxNEwyNCAxNEwyNCAxM0wyNiAxM0wyNiAxNEwyNSAxNEwyNSAxNUwyNiAxNUwyNiAxNkwyNSAxNkwyNSAxN0wyNyAxN0wyNyAxNUwyOSAxNUwyOSAxNEwyNyAxNEwyNyAxMVpNMTMgMTNMMTMgMTRMMTQgMTRMMTQgMTVMMTUgMTVMMTUgMTRMMTQgMTRMMTQgMTNaTTI2IDE0TDI2IDE1TDI3IDE1TDI3IDE0Wk02IDE3TDYgMThMNSAxOEw1IDE5TDQgMTlMNCAyMEw3IDIwTDcgMTlMOCAxOUw4IDIwTDEwIDIwTDEwIDE5TDkgMTlMOSAxN0w4IDE3TDggMThMNyAxOEw3IDE3Wk0yOCAxN0wyOCAxOEwyOSAxOEwyOSAxN1pNNiAxOEw2IDE5TDcgMTlMNyAxOFpNMTggMThMMTggMjBMMjAgMjBMMjAgMTlMMTkgMTlMMTkgMThaTTI1IDE4TDI1IDE5TDI2IDE5TDI2IDE4Wk0wIDIxTDAgMjNMMSAyM0wxIDIxWk02IDIxTDYgMjJMNCAyMkw0IDIzTDMgMjNMMyAyNEw0IDI0TDQgMjNMNiAyM0w2IDIyTDcgMjJMNyAyMVpNMjEgMjFMMjEgMjJMMjIgMjJMMjIgMjNMMjMgMjNMMjMgMjVMMjIgMjVMMjIgMjRMMjEgMjRMMjEgMjdMMjIgMjdMMjIgMjhMMjMgMjhMMjMgMjlMMjQgMjlMMjQgMzBMMjMgMzBMMjMgMzFMMjIgMzFMMjIgMzBMMjEgMzBMMjEgMzFMMjIgMzFMMjIgMzJMMjMgMzJMMjMgMzFMMjQgMzFMMjQgMzJMMjggMzJMMjggMjlMMjcgMjlMMjcgMzBMMjYgMzBMMjYgMjhMMjggMjhMMjggMjdMMjYgMjdMMjYgMjZMMjUgMjZMMjUgMjRMMjYgMjRMMjYgMjNMMjcgMjNMMjcgMjJMMjggMjJMMjggMjFMMjUgMjFMMjUgMjNMMjQgMjNMMjQgMjJMMjMgMjJMMjMgMjFaTTMwIDIyTDMwIDIzTDMyIDIzTDMyIDIyWk0xMSAyM0wxMSAyNEwxMiAyNEwxMiAyM1pNMCAyNEwwIDI5TDEgMjlMMSAyNkwyIDI2TDIgMjlMNCAyOUw0IDI3TDMgMjdMMyAyNkwyIDI2TDIgMjVMMSAyNUwxIDI0Wk0xNSAyNUwxNSAyNkwxNiAyNkwxNiAyN0wxNyAyN0wxNyAyNkwxNiAyNkwxNiAyNVpNMjMgMjVMMjMgMjZMMjIgMjZMMjIgMjdMMjMgMjdMMjMgMjhMMjUgMjhMMjUgMjZMMjQgMjZMMjQgMjVaTTIzIDI2TDIzIDI3TDI0IDI3TDI0IDI2Wk02IDI4TDYgMjlMNyAyOUw3IDI4Wk0xNCAyOEwxNCAzMEwxNSAzMEwxNSAzMkwxNiAzMkwxNiAzMUwxNyAzMUwxNyAyOUwxNiAyOUwxNiAzMEwxNSAzMEwxNSAyOFpNMjkgMjlMMjkgMzJMMzIgMzJMMzIgMjlaTTE5IDMwTDE5IDMxTDIwIDMxTDIwIDMwWk0yNSAzMEwyNSAzMUwyNiAzMUwyNiAzMFpNMzAgMzBMMzAgMzFMMzEgMzFMMzEgMzBaTTggMzJMOCAzN0wxMCAzN0wxMCAzNkwxMSAzNkwxMSAzNUw5IDM1TDkgMzJaTTI0IDMzTDI0IDM0TDI1IDM0TDI1IDM2TDI2IDM2TDI2IDM0TDI3IDM0TDI3IDM1TDI4IDM1TDI4IDMzWk0zMyAzM0wzMyAzNEwzNCAzNEwzNCAzM1pNMjkgMzRMMjkgMzVMMzEgMzVMMzEgMzRaTTAgMEwwIDdMNyA3TDcgMFpNMSAxTDEgNkw2IDZMNiAxWk0yIDJMMiA1TDUgNUw1IDJaTTMwIDBMMzAgN0wzNyA3TDM3IDBaTTMxIDFMMzEgNkwzNiA2TDM2IDFaTTMyIDJMMzIgNUwzNSA1TDM1IDJaTTAgMzBMMCAzN0w3IDM3TDcgMzBaTTEgMzFMMSAzNkw2IDM2TDYgMzFaTTIgMzJMMiAzNUw1IDM1TDUgMzJaIiBmaWxsPSIjMDAwMDAwIi8+PC9nPjwvZz48L3N2Zz4K	completed	\N	\N	0.00	\N	\N	pra
26	13	\N	POS-2026-00011	\N	\N	3000.00	percentage	0.00	0.00	16.00	480.00	3480.00	cash	191963FCRL011234202	100	submitted	13	2026-03-19 15:12:34	2026-03-24 07:00:11	8a1ab20aea5098e2fac75900115b7e232002ce25cdf0b07ec0a2f38c6676f95e	data:image/svg+xml;base64,PD94bWwgdmVyc2lvbj0iMS4wIiBlbmNvZGluZz0iVVRGLTgiPz4KPHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZlcnNpb249IjEuMSIgd2lkdGg9IjE1MCIgaGVpZ2h0PSIxNTAiIHZpZXdCb3g9IjAgMCAxNTAgMTUwIj48cmVjdCB4PSIwIiB5PSIwIiB3aWR0aD0iMTUwIiBoZWlnaHQ9IjE1MCIgZmlsbD0iI2ZmZmZmZiIvPjxnIHRyYW5zZm9ybT0ic2NhbGUoMy44NDYpIj48ZyB0cmFuc2Zvcm09InRyYW5zbGF0ZSgxLDEpIj48cGF0aCBmaWxsLXJ1bGU9ImV2ZW5vZGQiIGQ9Ik05IDBMOSAxTDggMUw4IDJMOSAyTDkgM0w4IDNMOCA0TDEwIDRMMTAgN0wxMSA3TDExIDhMNiA4TDYgOUw1IDlMNSA4TDAgOEwwIDExTDEgMTFMMSAxMEwyIDEwTDIgMTFMMyAxMUwzIDEyTDAgMTJMMCAxM0wxIDEzTDEgMTRMMCAxNEwwIDE1TDMgMTVMMyAxN0wyIDE3TDIgMTlMMCAxOUwwIDIwTDIgMjBMMiAyMUwxIDIxTDEgMjJMNCAyMkw0IDIxTDcgMjFMNyAyMEw4IDIwTDggMTlMNyAxOUw3IDE4TDkgMThMOSAyMEwxMCAyMEwxMCAyMUw5IDIxTDkgMjJMOCAyMkw4IDIzTDcgMjNMNyAyMkw1IDIyTDUgMjNMMyAyM0wzIDI1TDIgMjVMMiAyOEwzIDI4TDMgMjlMNCAyOUw0IDI4TDUgMjhMNSAyOUw3IDI5TDcgMjhMOSAyOEw5IDI3TDEwIDI3TDEwIDI2TDkgMjZMOSAyNUwxMCAyNUwxMCAyNEwxMyAyNEwxMyAyM0wxNCAyM0wxNCAyNkwxMyAyNkwxMyAyNUwxMSAyNUwxMSAyNkwxMiAyNkwxMiAyN0wxMSAyN0wxMSAyOEwxMCAyOEwxMCAyOUwxMSAyOUwxMSAzMEwxNCAzMEwxNCAzMUwxNSAzMUwxNSAzMkwxNiAzMkwxNiAzMUwxNyAzMUwxNyAyOUwxNiAyOUwxNiAzMEwxNCAzMEwxNCAyOUwxNSAyOUwxNSAyOEwxNCAyOEwxNCAyOUwxMSAyOUwxMSAyOEwxMyAyOEwxMyAyN0wxNCAyN0wxNCAyNkwxNSAyNkwxNSAyN0wxNiAyN0wxNiAyOEwxNyAyOEwxNyAyN0wxOCAyN0wxOCAyNUwyMCAyNUwyMCAyOEwyMiAyOEwyMiAyOUwxOSAyOUwxOSAzMEwxOCAzMEwxOCAzMkwyMCAzMkwyMCAzMUwyMSAzMUwyMSAzMkwyMiAzMkwyMiAzM0wyMyAzM0wyMyAzNEwyMiAzNEwyMiAzNUwyMSAzNUwyMSAzM0wxOSAzM0wxOSAzNEwxNyAzNEwxNyAzM0wxNCAzM0wxNCAzMkwxMyAzMkwxMyAzMUwxMiAzMUwxMiAzMkwxMyAzMkwxMyAzM0wxMSAzM0wxMSAzNEwxMyAzNEwxMyAzM0wxNCAzM0wxNCAzNEwxNSAzNEwxNSAzN0wxNiAzN0wxNiAzNUwxOCAzNUwxOCAzNkwxOSAzNkwxOSAzN0wyMCAzN0wyMCAzNkwxOSAzNkwxOSAzNEwyMCAzNEwyMCAzNUwyMSAzNUwyMSAzNkwyMiAzNkwyMiAzN0wyMyAzN0wyMyAzNEwyNCAzNEwyNCAzNkwyNSAzNkwyNSAzN0wyNyAzN0wyNyAzNkwyOSAzNkwyOSAzN0wzMCAzN0wzMCAzNkwzMSAzNkwzMSAzNUwzMiAzNUwzMiAzNEwzMyAzNEwzMyAzNUwzNiAzNUwzNiAzNkwzNCAzNkwzNCAzN0wzNyAzN0wzNyAzNEwzNSAzNEwzNSAzM0wzNyAzM0wzNyAzMkwzNiAzMkwzNiAzMUwzNyAzMUwzNyAzMEwzNCAzMEwzNCAzMUwzMyAzMUwzMyAyOUwzNSAyOUwzNSAyOEwyOSAyOEwyOSAyN0wzMCAyN0wzMCAyNkwyOSAyNkwyOSAyN0wyOCAyN0wyOCAyNkwyNiAyNkwyNiAyNUwyNyAyNUwyNyAyM0wyOCAyM0wyOCAyNEwyOSAyNEwyOSAyM0wyOCAyM0wyOCAyMkwyOSAyMkwyOSAyMUwzMSAyMUwzMSAyMEwzMiAyMEwzMiAyMUwzMyAyMUwzMyAyM0wzNiAyM0wzNiAyNEwzMiAyNEwzMiAyNUwzMSAyNUwzMSAyNEwzMCAyNEwzMCAyNUwzMSAyNUwzMSAyNkwzMiAyNkwzMiAyN0wzMyAyN0wzMyAyNkwzMiAyNkwzMiAyNUwzNSAyNUwzNSAyN0wzNiAyN0wzNiAyOEwzNyAyOEwzNyAyNkwzNiAyNkwzNiAyNUwzNyAyNUwzNyAyMkwzNiAyMkwzNiAyMUwzNyAyMUwzNyAxOEwzNiAxOEwzNiAxN0wzNyAxN0wzNyAxNEwzNiAxNEwzNiAxM0wzNyAxM0wzNyAxMkwzNiAxMkwzNiAxMUwzNyAxMUwzNyAxMEwzNiAxMEwzNiA4TDM1IDhMMzUgOUwzNCA5TDM0IDhMMzMgOEwzMyAxMkwzMiAxMkwzMiA4TDMxIDhMMzEgMTJMMjkgMTJMMjkgMTFMMzAgMTFMMzAgMTBMMjkgMTBMMjkgOUwzMCA5TDMwIDhMMjkgOEwyOSA5TDI3IDlMMjcgOEwyOCA4TDI4IDdMMjkgN0wyOSA0TDI3IDRMMjcgM0wyOSAzTDI5IDBMMjYgMEwyNiAxTDI0IDFMMjQgMEwyMyAwTDIzIDFMMjQgMUwyNCAyTDIxIDJMMjEgM0wyMCAzTDIwIDJMMTggMkwxOCAzTDE2IDNMMTYgMkwxNyAyTDE3IDFMMTUgMUwxNSAwTDE0IDBMMTQgMUwxNSAxTDE1IDJMMTQgMkwxNCAzTDEyIDNMMTIgMkwxMyAyTDEzIDFMMTEgMUwxMSAyTDEwIDJMMTAgMFpNMTkgMEwxOSAxTDIyIDFMMjIgMFpNMjYgMUwyNiAyTDI1IDJMMjUgM0wyMyAzTDIzIDRMMjIgNEwyMiAzTDIxIDNMMjEgNUwyMiA1TDIyIDZMMjEgNkwyMSA3TDIyIDdMMjIgOEwyMyA4TDIzIDlMMjQgOUwyNCAxMEwyMiAxMEwyMiAxMkwyMyAxMkwyMyAxM0wyMSAxM0wyMSAxNUwyMiAxNUwyMiAxNEwyMyAxNEwyMyAxNUwyNSAxNUwyNSAxNkwyMyAxNkwyMyAxOEwxOSAxOEwxOSAxN0wyMSAxN0wyMSAxNkwyMCAxNkwyMCAxNEwxOSAxNEwxOSAxM0wyMCAxM0wyMCAxMkwyMSAxMkwyMSAxMEwxOSAxMEwxOSAxMUwxOCAxMUwxOCA5TDE5IDlMMTkgOEwxOCA4TDE4IDZMMTkgNkwxOSA3TDIwIDdMMjAgNUwxOCA1TDE4IDZMMTcgNkwxNyA3TDE2IDdMMTYgNkwxNSA2TDE1IDVMMTcgNUwxNyA0TDE2IDRMMTYgM0wxNCAzTDE0IDRMMTUgNEwxNSA1TDEzIDVMMTMgOEwxMSA4TDExIDlMOSA5TDkgMTBMMTAgMTBMMTAgMTFMMTEgMTFMMTEgMTNMMTAgMTNMMTAgMTJMOSAxMkw5IDExTDggMTFMOCAxM0w5IDEzTDkgMTVMOCAxNUw4IDE0TDcgMTRMNyAxM0w2IDEzTDYgMTJMNyAxMkw3IDExTDYgMTFMNiAxMEw4IDEwTDggOUw2IDlMNiAxMEw0IDEwTDQgOUwyIDlMMiAxMEwzIDEwTDMgMTFMNSAxMUw1IDEyTDMgMTJMMyAxNEw0IDE0TDQgMTVMNSAxNUw1IDE2TDQgMTZMNCAxN0wzIDE3TDMgMThMNCAxOEw0IDE3TDUgMTdMNSAxOUwzIDE5TDMgMjFMNCAyMUw0IDIwTDcgMjBMNyAxOUw2IDE5TDYgMThMNyAxOEw3IDE3TDYgMTdMNiAxNkw3IDE2TDcgMTVMOCAxNUw4IDE3TDkgMTdMOSAxOEwxMSAxOEwxMSAyMUwxMCAyMUwxMCAyMkwxMSAyMkwxMSAyM0wxMyAyM0wxMyAyMkwxNCAyMkwxNCAyM0wxNSAyM0wxNSAyNEwxNiAyNEwxNiAyM0wxNyAyM0wxNyAyNEwxOCAyNEwxOCAyMkwxOSAyMkwxOSAyM0wyMCAyM0wyMCAyMEwyMSAyMEwyMSAxOUwyNCAxOUwyNCAyMEwyNiAyMEwyNiAxOUwyNyAxOUwyNyAxOEwyOCAxOEwyOCAyMEwyOSAyMEwyOSAxOUwzMCAxOUwzMCAxOEwzMSAxOEwzMSAxOUwzMiAxOUwzMiAxOEwzMyAxOEwzMyAyMUwzNCAyMUwzNCAyMkwzNSAyMkwzNSAyMUwzNCAyMUwzNCAyMEwzNiAyMEwzNiAxOUwzNCAxOUwzNCAxN0wzMiAxN0wzMiAxOEwzMSAxOEwzMSAxN0wyOSAxN0wyOSAxNkwzMyAxNkwzMyAxNUwzNCAxNUwzNCAxNkwzNSAxNkwzNSAxN0wzNiAxN0wzNiAxNUwzNCAxNUwzNCAxM0wzMiAxM0wzMiAxNEwzMSAxNEwzMSAxM0wyOSAxM0wyOSAxMkwyOCAxMkwyOCAxMUwyNyAxMUwyNyA5TDI2IDlMMjYgMTBMMjUgMTBMMjUgOUwyNCA5TDI0IDZMMjUgNkwyNSA4TDI3IDhMMjcgN0wyOCA3TDI4IDZMMjcgNkwyNyA3TDI2IDdMMjYgNkwyNSA2TDI1IDVMMjcgNUwyNyA0TDI2IDRMMjYgMkwyNyAyTDI3IDFaTTEwIDNMMTAgNEwxMSA0TDExIDdMMTIgN0wxMiA0TDExIDRMMTEgM1pNMTkgM0wxOSA0TDIwIDRMMjAgM1pNMjMgNEwyMyA1TDI0IDVMMjQgNFpNOCA1TDggN0w5IDdMOSA1Wk0xNCA2TDE0IDhMMTMgOEwxMyA5TDExIDlMMTEgMTBMMTIgMTBMMTIgMTJMMTMgMTJMMTMgMTNMMTEgMTNMMTEgMTRMMTIgMTRMMTIgMTVMMTAgMTVMMTAgMTZMMTMgMTZMMTMgMTVMMTQgMTVMMTQgMTdMMTUgMTdMMTUgMTVMMTYgMTVMMTYgMTZMMTcgMTZMMTcgMTdMMTYgMTdMMTYgMThMMTMgMThMMTMgMTdMMTEgMTdMMTEgMThMMTIgMThMMTIgMTlMMTYgMTlMMTYgMjBMMTcgMjBMMTcgMjFMMTUgMjFMMTUgMjBMMTQgMjBMMTQgMjFMMTUgMjFMMTUgMjNMMTYgMjNMMTYgMjJMMTggMjJMMTggMjBMMjAgMjBMMjAgMTlMMTkgMTlMMTkgMThMMTggMThMMTggMjBMMTcgMjBMMTcgMTlMMTYgMTlMMTYgMThMMTcgMThMMTcgMTdMMTggMTdMMTggMTZMMTcgMTZMMTcgMTVMMTkgMTVMMTkgMTRMMTUgMTRMMTUgMTNMMTQgMTNMMTQgMTJMMTMgMTJMMTMgMTFMMTUgMTFMMTUgMTJMMTYgMTJMMTYgMTNMMTggMTNMMTggMTJMMTcgMTJMMTcgMTBMMTUgMTBMMTUgOUwxOCA5TDE4IDhMMTYgOEwxNiA3TDE1IDdMMTUgNlpNMjIgNkwyMiA3TDIzIDdMMjMgNlpNMTQgOEwxNCA5TDEzIDlMMTMgMTBMMTQgMTBMMTQgOUwxNSA5TDE1IDhaTTIwIDhMMjAgOUwyMSA5TDIxIDhaTTM1IDEwTDM1IDExTDM0IDExTDM0IDEyTDM1IDEyTDM1IDEzTDM2IDEzTDM2IDEyTDM1IDEyTDM1IDExTDM2IDExTDM2IDEwWk0yNiAxMUwyNiAxMkwyNCAxMkwyNCAxM0wyMyAxM0wyMyAxNEwyNCAxNEwyNCAxM0wyNiAxM0wyNiAxNEwyNSAxNEwyNSAxNUwyNiAxNUwyNiAxNkwyNSAxNkwyNSAxN0wyNyAxN0wyNyAxNUwyOSAxNUwyOSAxNEwyNyAxNEwyNyAxMVpNNCAxM0w0IDE0TDUgMTRMNSAxNUw3IDE1TDcgMTRMNiAxNEw2IDEzWk0xMyAxM0wxMyAxNEwxNCAxNEwxNCAxNUwxNSAxNUwxNSAxNEwxNCAxNEwxNCAxM1pNMjYgMTRMMjYgMTVMMjcgMTVMMjcgMTRaTTAgMTZMMCAxN0wxIDE3TDEgMTZaTTI4IDE3TDI4IDE4TDI5IDE4TDI5IDE3Wk0yNSAxOEwyNSAxOUwyNiAxOUwyNiAxOFpNMTEgMjFMMTEgMjJMMTMgMjJMMTMgMjFaTTIxIDIxTDIxIDIyTDIyIDIyTDIyIDIzTDIzIDIzTDIzIDI1TDIyIDI1TDIyIDI0TDIxIDI0TDIxIDI3TDIyIDI3TDIyIDI4TDIzIDI4TDIzIDI5TDI0IDI5TDI0IDMwTDIzIDMwTDIzIDMxTDIyIDMxTDIyIDMwTDIxIDMwTDIxIDMxTDIyIDMxTDIyIDMyTDIzIDMyTDIzIDMxTDI0IDMxTDI0IDMyTDI4IDMyTDI4IDI5TDI3IDI5TDI3IDMwTDI2IDMwTDI2IDI4TDI4IDI4TDI4IDI3TDI2IDI3TDI2IDI2TDI1IDI2TDI1IDI0TDI2IDI0TDI2IDIzTDI3IDIzTDI3IDIyTDI4IDIyTDI4IDIxTDI1IDIxTDI1IDIzTDI0IDIzTDI0IDIyTDIzIDIyTDIzIDIxWk0zMCAyMkwzMCAyM0wzMiAyM0wzMiAyMlpNMCAyM0wwIDI5TDEgMjlMMSAyNEwyIDI0TDIgMjNaTTYgMjNMNiAyNEw1IDI0TDUgMjVMNCAyNUw0IDI2TDMgMjZMMyAyN0w0IDI3TDQgMjZMNSAyNkw1IDI3TDYgMjdMNiAyOEw3IDI4TDcgMjdMOCAyN0w4IDI1TDkgMjVMOSAyNEwxMCAyNEwxMCAyM0w5IDIzTDkgMjRMOCAyNEw4IDI1TDYgMjVMNiAyNEw3IDI0TDcgMjNaTTE1IDI1TDE1IDI2TDE2IDI2TDE2IDI3TDE3IDI3TDE3IDI2TDE2IDI2TDE2IDI1Wk0yMyAyNUwyMyAyNkwyMiAyNkwyMiAyN0wyMyAyN0wyMyAyOEwyNSAyOEwyNSAyNkwyNCAyNkwyNCAyNVpNNiAyNkw2IDI3TDcgMjdMNyAyNlpNMjMgMjZMMjMgMjdMMjQgMjdMMjQgMjZaTTggMjlMOCAzMUw5IDMxTDkgMjlaTTI5IDI5TDI5IDMyTDMyIDMyTDMyIDI5Wk0xOSAzMEwxOSAzMUwyMCAzMUwyMCAzMFpNMjUgMzBMMjUgMzFMMjYgMzFMMjYgMzBaTTMwIDMwTDMwIDMxTDMxIDMxTDMxIDMwWk0xMCAzMUwxMCAzMkwxMSAzMkwxMSAzMVpNMzQgMzFMMzQgMzJMMzUgMzJMMzUgMzFaTTggMzJMOCAzN0w5IDM3TDkgMzRMMTAgMzRMMTAgMzNMOSAzM0w5IDMyWk0yNCAzM0wyNCAzNEwyNSAzNEwyNSAzNkwyNiAzNkwyNiAzNEwyNyAzNEwyNyAzNUwyOCAzNUwyOCAzM1pNMzMgMzNMMzMgMzRMMzQgMzRMMzQgMzNaTTI5IDM0TDI5IDM1TDMxIDM1TDMxIDM0Wk0xMiAzNUwxMiAzNkwxMyAzNkwxMyAzN0wxNCAzN0wxNCAzNkwxMyAzNkwxMyAzNVpNMCAwTDAgN0w3IDdMNyAwWk0xIDFMMSA2TDYgNkw2IDFaTTIgMkwyIDVMNSA1TDUgMlpNMzAgMEwzMCA3TDM3IDdMMzcgMFpNMzEgMUwzMSA2TDM2IDZMMzYgMVpNMzIgMkwzMiA1TDM1IDVMMzUgMlpNMCAzMEwwIDM3TDcgMzdMNyAzMFpNMSAzMUwxIDM2TDYgMzZMNiAzMVpNMiAzMkwyIDM1TDUgMzVMNSAzMloiIGZpbGw9IiMwMDAwMDAiLz48L2c+PC9nPjwvc3ZnPgo=	completed	\N	\N	0.00	\N	\N	pra
24	11	\N	POS-2026-00001	\N	\N	0.00	percentage	0.00	0.00	16.00	0.00	0.00	cash	\N	\N	local	11	2026-03-19 10:40:13	2026-03-19 10:40:13	\N	\N	draft	\N	2026-03-19 10:40:13	0.00	\N	\N	pra
23	13	\N	POS-2026-00010	shoaib	\N	3128.00	percentage	20.00	625.60	16.00	400.38	2902.78	cash	191963FCMO2838857174	100	submitted	13	2026-03-19 10:27:29	2026-03-19 14:51:25	3a15286b853164f59adb53e482f07ab9d1c0e4337456d07042a8516cf0c997f6	data:image/svg+xml;base64,PD94bWwgdmVyc2lvbj0iMS4wIiBlbmNvZGluZz0iVVRGLTgiPz4KPHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZlcnNpb249IjEuMSIgd2lkdGg9IjE1MCIgaGVpZ2h0PSIxNTAiIHZpZXdCb3g9IjAgMCAxNTAgMTUwIj48cmVjdCB4PSIwIiB5PSIwIiB3aWR0aD0iMTUwIiBoZWlnaHQ9IjE1MCIgZmlsbD0iI2ZmZmZmZiIvPjxnIHRyYW5zZm9ybT0ic2NhbGUoMy44NDYpIj48ZyB0cmFuc2Zvcm09InRyYW5zbGF0ZSgxLDEpIj48cGF0aCBmaWxsLXJ1bGU9ImV2ZW5vZGQiIGQ9Ik05IDBMOSAxTDggMUw4IDNMOSAzTDkgNEw4IDRMOCA4TDYgOEw2IDlMOCA5TDggMTFMNyAxMUw3IDEwTDYgMTBMNiAxMUw0IDExTDQgOEwzIDhMMyA5TDIgOUwyIDhMMCA4TDAgMTFMMSAxMUwxIDlMMiA5TDIgMTFMMyAxMUwzIDEzTDIgMTNMMiAxMkwwIDEyTDAgMTdMMSAxN0wxIDIwTDMgMjBMMyAyMUwwIDIxTDAgMjVMMSAyNUwxIDI2TDAgMjZMMCAyN0wxIDI3TDEgMjhMMCAyOEwwIDI5TDQgMjlMNCAyOEw1IDI4TDUgMjlMOCAyOUw4IDMxTDkgMzFMOSAzM0w4IDMzTDggMzRMOSAzNEw5IDM1TDggMzVMOCAzN0wxMCAzN0wxMCAzNEwxMSAzNEwxMSAzM0wxMiAzM0wxMiAzMkwxMSAzMkwxMSAzMEwxMiAzMEwxMiAyN0wxMyAyN0wxMyAyOUwxNCAyOUwxNCAzMEwxMyAzMEwxMyAzMUwxNCAzMUwxNCAzMkwxMyAzMkwxMyAzNEwxMiAzNEwxMiAzNUwxMyAzNUwxMyAzN0wxNSAzN0wxNSAzNkwxNiAzNkwxNiAzN0wxNyAzN0wxNyAzNkwxNiAzNkwxNiAzNUwxOSAzNUwxOSAzN0wyMiAzN0wyMiAzNkwyMyAzNkwyMyAzNEwyMSAzNEwyMSAzM0wyMiAzM0wyMiAzMkwyMyAzMkwyMyAzMUwyMiAzMUwyMiAzMEwyNSAzMEwyNSAzMUwyNCAzMUwyNCAzNUwyNSAzNUwyNSAzN0wyNiAzN0wyNiAzM0wyNyAzM0wyNyAzMkwyNSAzMkwyNSAzMUwyOCAzMUwyOCAzNEwyNyAzNEwyNyAzN0wzMCAzN0wzMCAzNUwyOSAzNUwyOSAzM0wzMiAzM0wzMiAzNUwzMSAzNUwzMSAzNkwzMiAzNkwzMiAzN0wzNCAzN0wzNCAzNkwzMyAzNkwzMyAzMkwzNSAzMkwzNSAzM0wzNCAzM0wzNCAzNUwzNyAzNUwzNyAzM0wzNiAzM0wzNiAzMUwzNyAzMUwzNyAyOUwzNiAyOUwzNiAzMEwzNSAzMEwzNSAzMUwzNCAzMUwzNCAzMEwzMyAzMEwzMyAyOEwzMiAyOEwzMiAyN0wzNSAyN0wzNSAyOEwzNCAyOEwzNCAyOUwzNSAyOUwzNSAyOEwzNiAyOEwzNiAyN0wzNyAyN0wzNyAyNEwzNSAyNEwzNSAyNUwzNCAyNUwzNCAyNkwzMiAyNkwzMiAyNUwzMSAyNUwzMSAyNEwzMyAyNEwzMyAyMkwzNCAyMkwzNCAyM0wzNyAyM0wzNyAyMEwzNiAyMEwzNiAxOUwzNyAxOUwzNyAxNkwzNiAxNkwzNiAxNUwzNyAxNUwzNyA5TDM2IDlMMzYgOEwzNCA4TDM0IDlMMzMgOUwzMyA4TDMwIDhMMzAgOUwyOSA5TDI5IDRMMjggNEwyOCA1TDI3IDVMMjcgMkwyOCAyTDI4IDFMMjcgMUwyNyAyTDI2IDJMMjYgMUwyNSAxTDI1IDJMMjQgMkwyNCAwTDIyIDBMMjIgMkwyNCAyTDI0IDNMMjIgM0wyMiA0TDIxIDRMMjEgM0wyMCAzTDIwIDJMMTkgMkwxOSAxTDIwIDFMMjAgMEwxOSAwTDE5IDFMMTcgMUwxNyAwTDE1IDBMMTUgMkwxNCAyTDE0IDNMMTUgM0wxNSAyTDE2IDJMMTYgNEwxNSA0TDE1IDVMMTMgNUwxMyAzTDEyIDNMMTIgMUwxMyAxTDEzIDBMMTIgMEwxMiAxTDExIDFMMTEgMFpNOSAxTDkgMkwxMSAyTDExIDFaTTE2IDFMMTYgMkwxNyAyTDE3IDFaTTI1IDJMMjUgM0wyNiAzTDI2IDJaTTE3IDNMMTcgNEwxNiA0TDE2IDVMMTcgNUwxNyA0TDE5IDRMMTkgNUwyMCA1TDIwIDZMMTkgNkwxOSA3TDE4IDdMMTggNkwxNyA2TDE3IDdMMTggN0wxOCA4TDE0IDhMMTQgNkwxMyA2TDEzIDhMMTIgOEwxMiA1TDExIDVMMTEgNEw5IDRMOSA1TDEwIDVMMTAgNkw5IDZMOSA3TDEwIDdMMTAgOEw4IDhMOCA5TDkgOUw5IDExTDEwIDExTDEwIDhMMTIgOEwxMiA5TDExIDlMMTEgMTNMMTAgMTNMMTAgMTJMOSAxMkw5IDE0TDggMTRMOCAxNkw3IDE2TDcgMTVMNSAxNUw1IDE0TDcgMTRMNyAxM0w2IDEzTDYgMTJMNyAxMkw3IDExTDYgMTFMNiAxMkw0IDEyTDQgMTNMMyAxM0wzIDE1TDIgMTVMMiAxNkwzIDE2TDMgMTVMNCAxNUw0IDE3TDUgMTdMNSAxNkw3IDE2TDcgMTdMNiAxN0w2IDE4TDcgMThMNyAxN0w4IDE3TDggMTlMOSAxOUw5IDIxTDEwIDIxTDEwIDIyTDExIDIyTDExIDIzTDEwIDIzTDEwIDI0TDkgMjRMOSAyNUw2IDI1TDYgMjRMOCAyNEw4IDIwTDcgMjBMNyAxOUw1IDE5TDUgMjFMNCAyMUw0IDIzTDIgMjNMMiAyMkwxIDIyTDEgMjVMMyAyNUwzIDI0TDQgMjRMNCAyNUw2IDI1TDYgMjZMMyAyNkwzIDI3TDIgMjdMMiAyNkwxIDI2TDEgMjdMMiAyN0wyIDI4TDQgMjhMNCAyN0w1IDI3TDUgMjhMNyAyOEw3IDI3TDggMjdMOCAyNkw5IDI2TDkgMjdMMTAgMjdMMTAgMjhMMTEgMjhMMTEgMjZMMTMgMjZMMTMgMjdMMTQgMjdMMTQgMjZMMTUgMjZMMTUgMjhMMTQgMjhMMTQgMjlMMTUgMjlMMTUgMzBMMTQgMzBMMTQgMzFMMTggMzFMMTggMzNMMTkgMzNMMTkgMzRMMjAgMzRMMjAgMzVMMjEgMzVMMjEgMzRMMjAgMzRMMjAgMzNMMjEgMzNMMjEgMjlMMTkgMjlMMTkgMjhMMTYgMjhMMTYgMjZMMTcgMjZMMTcgMjdMMjAgMjdMMjAgMjVMMjEgMjVMMjEgMjZMMjIgMjZMMjIgMjhMMjMgMjhMMjMgMjlMMjUgMjlMMjUgMjdMMjYgMjdMMjYgMzBMMjggMzBMMjggMjVMMjcgMjVMMjcgMjRMMjggMjRMMjggMjNMMjkgMjNMMjkgMjVMMzAgMjVMMzAgMjJMMjggMjJMMjggMjFMMzAgMjFMMzAgMjBMMzEgMjBMMzEgMjFMMzIgMjFMMzIgMjJMMzEgMjJMMzEgMjNMMzIgMjNMMzIgMjJMMzMgMjJMMzMgMjFMMzIgMjFMMzIgMjBMMzEgMjBMMzEgMTlMMzMgMTlMMzMgMjBMMzQgMjBMMzQgMjJMMzYgMjJMMzYgMjBMMzQgMjBMMzQgMTlMMzUgMTlMMzUgMThMMzQgMThMMzQgMTZMMzUgMTZMMzUgMTdMMzYgMTdMMzYgMTZMMzUgMTZMMzUgMTVMMzYgMTVMMzYgMTJMMzUgMTJMMzUgMTFMMzYgMTFMMzYgOUwzNSA5TDM1IDExTDM0IDExTDM0IDEwTDMzIDEwTDMzIDlMMzEgOUwzMSAxMEwzMCAxMEwzMCAxMUwyOSAxMUwyOSAxMkwzMCAxMkwzMCAxMUwzMSAxMUwzMSAxMkwzMiAxMkwzMiAxNEwzMCAxNEwzMCAxNUwzMiAxNUwzMiAxNkwzMSAxNkwzMSAxOUwzMCAxOUwzMCAxN0wyOSAxN0wyOSAxNEwyOCAxNEwyOCAxM0wyNyAxM0wyNyAxMkwyOCAxMkwyOCAxMEwyNyAxMEwyNyA5TDI1IDlMMjUgMTBMMjQgMTBMMjQgOUwyMyA5TDIzIDhMMjAgOEwyMCA5TDE5IDlMMTkgN0wyMCA3TDIwIDZMMjEgNkwyMSA3TDIyIDdMMjIgNkwyMyA2TDIzIDdMMjQgN0wyNCA4TDI3IDhMMjcgN0wyOCA3TDI4IDZMMjcgNkwyNyA1TDI1IDVMMjUgNEwyNCA0TDI0IDVMMjMgNUwyMyA0TDIyIDRMMjIgNkwyMSA2TDIxIDRMMTkgNEwxOSAzWk0yNCA1TDI0IDdMMjUgN0wyNSA1Wk0xMCA2TDEwIDdMMTEgN0wxMSA2Wk0xNSA2TDE1IDdMMTYgN0wxNiA2Wk0yNiA2TDI2IDdMMjcgN0wyNyA2Wk0xMyA4TDEzIDEwTDE1IDEwTDE1IDlMMTQgOUwxNCA4Wk0xNyA5TDE3IDEwTDE2IDEwTDE2IDExTDEzIDExTDEzIDEzTDE0IDEzTDE0IDE0TDEzIDE0TDEzIDE1TDEyIDE1TDEyIDE0TDExIDE0TDExIDE1TDEwIDE1TDEwIDE2TDExIDE2TDExIDE1TDEyIDE1TDEyIDE2TDEzIDE2TDEzIDE5TDExIDE5TDExIDE4TDEyIDE4TDEyIDE3TDExIDE3TDExIDE4TDkgMThMOSAxOUwxMCAxOUwxMCAyMUwxMiAyMUwxMiAyMEwxMyAyMEwxMyAxOUwxNCAxOUwxNCAxOEwxNSAxOEwxNSAxN0wxNiAxN0wxNiAxOUwxNyAxOUwxNyAyMEwxNCAyMEwxNCAyMUwxMyAyMUwxMyAyMkwxMiAyMkwxMiAyM0wxMyAyM0wxMyAyNEwxNCAyNEwxNCAyM0wxNSAyM0wxNSAyMkwxNyAyMkwxNyAyMEwxOCAyMEwxOCAxOUwxOSAxOUwxOSAyMEwyMCAyMEwyMCAyMUwxOCAyMUwxOCAyM0wyMCAyM0wyMCAyMkwyMSAyMkwyMSAyMEwyMiAyMEwyMiAxOUwyMyAxOUwyMyAxNUwyNCAxNUwyNCAxNEwyNSAxNEwyNSAxNkwyNyAxNkwyNyAxN0wyNCAxN0wyNCAxOEwyNSAxOEwyNSAxOUwyNCAxOUwyNCAyMUwyNSAyMUwyNSAyMkwyNCAyMkwyNCAyNEwyNSAyNEwyNSAyNUwyNCAyNUwyNCAyNkwyMyAyNkwyMyAyNUwyMiAyNUwyMiAyNEwyMyAyNEwyMyAyM0wyMSAyM0wyMSAyNUwyMiAyNUwyMiAyNkwyMyAyNkwyMyAyN0wyNSAyN0wyNSAyNkwyNiAyNkwyNiAyN0wyNyAyN0wyNyAyNkwyNiAyNkwyNiAyM0wyNyAyM0wyNyAyMkwyNiAyMkwyNiAyMUwyNyAyMUwyNyAyMEwyOSAyMEwyOSAxN0wyOCAxN0wyOCAxNkwyNyAxNkwyNyAxNUwyNiAxNUwyNiAxNEwyNyAxNEwyNyAxM0wyNiAxM0wyNiAxMUwyNyAxMUwyNyAxMEwyNSAxMEwyNSAxMUwyNCAxMUwyNCAxNEwyMyAxNEwyMyAxM0wyMiAxM0wyMiAxNEwyMSAxNEwyMSAxM0wxOCAxM0wxOCAxMkwyMiAxMkwyMiAxMUwyMSAxMUwyMSAxMEwyMyAxMEwyMyA5TDIwIDlMMjAgMTBMMTggMTBMMTggOVpNMTcgMTBMMTcgMTJMMTYgMTJMMTYgMTNMMTUgMTNMMTUgMTRMMTQgMTRMMTQgMTVMMTUgMTVMMTUgMTZMMTQgMTZMMTQgMTdMMTUgMTdMMTUgMTZMMTYgMTZMMTYgMTdMMTcgMTdMMTcgMTlMMTggMTlMMTggMTdMMTkgMTdMMTkgMTlMMjAgMTlMMjAgMjBMMjEgMjBMMjEgMTlMMjAgMTlMMjAgMThMMjEgMThMMjEgMTdMMjIgMTdMMjIgMTZMMjEgMTZMMjEgMTdMMTkgMTdMMTkgMTZMMTggMTZMMTggMTdMMTcgMTdMMTcgMTZMMTYgMTZMMTYgMTVMMTggMTVMMTggMTRMMTYgMTRMMTYgMTNMMTcgMTNMMTcgMTJMMTggMTJMMTggMTBaTTMyIDEwTDMyIDEyTDM0IDEyTDM0IDExTDMzIDExTDMzIDEwWk0xIDEzTDEgMTRMMiAxNEwyIDEzWk00IDEzTDQgMTRMNSAxNEw1IDEzWk0yNSAxM0wyNSAxNEwyNiAxNEwyNiAxM1pNMTkgMTRMMTkgMTVMMjAgMTVMMjAgMTRaTTIyIDE0TDIyIDE1TDIzIDE1TDIzIDE0Wk0zMyAxNEwzMyAxNkwzNCAxNkwzNCAxNFpNMiAxN0wyIDE5TDMgMTlMMyAyMEw0IDIwTDQgMThMMyAxOEwzIDE3Wk0yNyAxN0wyNyAxOUwyOCAxOUwyOCAxN1pNMzIgMTdMMzIgMThMMzMgMThMMzMgMTlMMzQgMTlMMzQgMThMMzMgMThMMzMgMTdaTTI1IDE5TDI1IDIwTDI2IDIwTDI2IDE5Wk02IDIwTDYgMjFMNSAyMUw1IDIzTDcgMjNMNyAyMkw2IDIyTDYgMjFMNyAyMUw3IDIwWk0xNCAyMUwxNCAyMkwxMyAyMkwxMyAyM0wxNCAyM0wxNCAyMkwxNSAyMkwxNSAyMVpNMTYgMjNMMTYgMjRMMTcgMjRMMTcgMjVMMTggMjVMMTggMjZMMTkgMjZMMTkgMjVMMjAgMjVMMjAgMjRMMTcgMjRMMTcgMjNaTTExIDI0TDExIDI1TDkgMjVMOSAyNkwxMSAyNkwxMSAyNUwxMiAyNUwxMiAyNFpNMTMgMjVMMTMgMjZMMTQgMjZMMTQgMjVaTTM1IDI1TDM1IDI3TDM2IDI3TDM2IDI1Wk02IDI2TDYgMjdMNyAyN0w3IDI2Wk0yOSAyNkwyOSAyN0wzMiAyN0wzMiAyNlpNMTUgMjhMMTUgMjlMMTYgMjlMMTYgMzBMMTcgMzBMMTcgMjlMMTYgMjlMMTYgMjhaTTE4IDI5TDE4IDMxTDE5IDMxTDE5IDMyTDIwIDMyTDIwIDMxTDE5IDMxTDE5IDI5Wk0yOSAyOUwyOSAzMkwzMiAzMkwzMiAyOVpNMzAgMzBMMzAgMzFMMzEgMzFMMzEgMzBaTTE0IDMyTDE0IDMzTDE1IDMzTDE1IDMyWk0xMyAzNEwxMyAzNUwxNCAzNUwxNCAzNFpNMTUgMzRMMTUgMzVMMTYgMzVMMTYgMzRaTTExIDM2TDExIDM3TDEyIDM3TDEyIDM2Wk0zNSAzNkwzNSAzN0wzNyAzN0wzNyAzNlpNMCAwTDAgN0w3IDdMNyAwWk0xIDFMMSA2TDYgNkw2IDFaTTIgMkwyIDVMNSA1TDUgMlpNMzAgMEwzMCA3TDM3IDdMMzcgMFpNMzEgMUwzMSA2TDM2IDZMMzYgMVpNMzIgMkwzMiA1TDM1IDVMMzUgMlpNMCAzMEwwIDM3TDcgMzdMNyAzMFpNMSAzMUwxIDM2TDYgMzZMNiAzMVpNMiAzMkwyIDM1TDUgMzVMNSAzMloiIGZpbGw9IiMwMDAwMDAiLz48L2c+PC9nPjwvc3ZnPgo=	completed	\N	\N	0.00	48f651cb5ca7f31d8a6abbfd620f87f0c8acaa4ad5451d31e01eb41251101cd4	\N	pra
15	13	\N	POS-2026-00002	\N	\N	1000.00	percentage	0.00	0.00	16.00	160.00	1160.00	cash	191963FCMN5630246564	100	submitted	13	2026-03-09 13:04:05	2026-03-19 09:56:31	f1452abcb1497207a90b6eff75d3425a11b7a4d10c6ed432fb56541bc4d62ffd	data:image/svg+xml;base64,PD94bWwgdmVyc2lvbj0iMS4wIiBlbmNvZGluZz0iVVRGLTgiPz4KPHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZlcnNpb249IjEuMSIgd2lkdGg9IjE1MCIgaGVpZ2h0PSIxNTAiIHZpZXdCb3g9IjAgMCAxNTAgMTUwIj48cmVjdCB4PSIwIiB5PSIwIiB3aWR0aD0iMTUwIiBoZWlnaHQ9IjE1MCIgZmlsbD0iI2ZmZmZmZiIvPjxnIHRyYW5zZm9ybT0ic2NhbGUoMy44NDYpIj48ZyB0cmFuc2Zvcm09InRyYW5zbGF0ZSgxLDEpIj48cGF0aCBmaWxsLXJ1bGU9ImV2ZW5vZGQiIGQ9Ik05IDBMOSAxTDggMUw4IDNMOSAzTDkgNEw4IDRMOCA4TDYgOEw2IDlMNCA5TDQgOEwzIDhMMyA5TDIgOUwyIDhMMCA4TDAgMTBMMSAxMEwxIDlMMiA5TDIgMTZMMSAxNkwxIDE3TDAgMTdMMCAxOUwxIDE5TDEgMThMMiAxOEwyIDE5TDMgMTlMMyAyMkwxIDIyTDEgMjRMMCAyNEwwIDI1TDEgMjVMMSAyNkwwIDI2TDAgMjdMMSAyN0wxIDI4TDAgMjhMMCAyOUw0IDI5TDQgMjhMNSAyOEw1IDI5TDggMjlMOCAzMUw5IDMxTDkgMzJMMTAgMzJMMTAgMzNMOCAzM0w4IDM0TDkgMzRMOSAzNUw4IDM1TDggMzdMMTEgMzdMMTEgMzZMMTMgMzZMMTMgMzdMMTUgMzdMMTUgMzZMMTYgMzZMMTYgMzdMMTcgMzdMMTcgMzZMMTYgMzZMMTYgMzVMMTkgMzVMMTkgMzdMMjIgMzdMMjIgMzZMMjMgMzZMMjMgMzRMMjEgMzRMMjEgMzNMMjIgMzNMMjIgMzJMMjMgMzJMMjMgMzFMMjIgMzFMMjIgMzBMMjUgMzBMMjUgMzFMMjQgMzFMMjQgMzVMMjUgMzVMMjUgMzdMMjYgMzdMMjYgMzNMMjcgMzNMMjcgMzJMMjUgMzJMMjUgMzFMMjggMzFMMjggMzRMMjcgMzRMMjcgMzdMMzAgMzdMMzAgMzVMMjkgMzVMMjkgMzNMMzIgMzNMMzIgMzVMMzEgMzVMMzEgMzZMMzIgMzZMMzIgMzdMMzQgMzdMMzQgMzZMMzMgMzZMMzMgMzJMMzUgMzJMMzUgMzNMMzQgMzNMMzQgMzVMMzcgMzVMMzcgMzNMMzYgMzNMMzYgMzFMMzcgMzFMMzcgMjlMMzYgMjlMMzYgMzBMMzUgMzBMMzUgMzFMMzQgMzFMMzQgMzBMMzMgMzBMMzMgMjhMMzIgMjhMMzIgMjdMMzUgMjdMMzUgMjhMMzQgMjhMMzQgMjlMMzUgMjlMMzUgMjhMMzYgMjhMMzYgMjdMMzcgMjdMMzcgMjRMMzUgMjRMMzUgMjVMMzQgMjVMMzQgMjZMMzIgMjZMMzIgMjVMMzEgMjVMMzEgMjRMMzMgMjRMMzMgMjJMMzQgMjJMMzQgMjNMMzcgMjNMMzcgMjBMMzYgMjBMMzYgMTlMMzcgMTlMMzcgMTZMMzYgMTZMMzYgMTVMMzcgMTVMMzcgOUwzNiA5TDM2IDhMMzQgOEwzNCA5TDMzIDlMMzMgOEwzMCA4TDMwIDlMMjkgOUwyOSA0TDI4IDRMMjggNUwyNyA1TDI3IDJMMjggMkwyOCAxTDI3IDFMMjcgMkwyNiAyTDI2IDFMMjUgMUwyNSAyTDI0IDJMMjQgMEwyMiAwTDIyIDJMMjQgMkwyNCAzTDIyIDNMMjIgNEwyMSA0TDIxIDNMMjAgM0wyMCAyTDE5IDJMMTkgMUwyMCAxTDIwIDBMMTkgMEwxOSAxTDE3IDFMMTcgMEwxNSAwTDE1IDJMMTQgMkwxNCAzTDE1IDNMMTUgMkwxNiAyTDE2IDRMMTUgNEwxNSA1TDEzIDVMMTMgM0wxMiAzTDEyIDFMMTMgMUwxMyAwTDEyIDBMMTIgMUwxMSAxTDExIDBaTTkgMUw5IDJMMTEgMkwxMSAxWk0xNiAxTDE2IDJMMTcgMkwxNyAxWk0yNSAyTDI1IDNMMjYgM0wyNiAyWk0xNyAzTDE3IDRMMTYgNEwxNiA1TDE3IDVMMTcgNEwxOSA0TDE5IDVMMjAgNUwyMCA2TDE5IDZMMTkgN0wxOCA3TDE4IDZMMTcgNkwxNyA3TDE4IDdMMTggOEwxNCA4TDE0IDZMMTMgNkwxMyA1TDExIDVMMTEgNEw5IDRMOSA1TDEwIDVMMTAgNkw5IDZMOSA3TDEwIDdMMTAgOEw4IDhMOCA5TDYgOUw2IDEwTDUgMTBMNSAxMUw0IDExTDQgOUwzIDlMMyAxMUw0IDExTDQgMTJMMyAxMkwzIDEzTDYgMTNMNiAxNEw1IDE0TDUgMTVMNiAxNUw2IDE2TDMgMTZMMyAxN0wyIDE3TDIgMThMNCAxOEw0IDE5TDUgMTlMNSAyMEw0IDIwTDQgMjNMMyAyM0wzIDI0TDIgMjRMMiAyNkwxIDI2TDEgMjdMMiAyN0wyIDI4TDMgMjhMMyAyNUw0IDI1TDQgMjdMNSAyN0w1IDI2TDggMjZMOCAyNUw0IDI1TDQgMjNMNiAyM0w2IDI0TDggMjRMOCAyM0wxMCAyM0wxMCAyNEw5IDI0TDkgMjZMMTMgMjZMMTMgMjdMMTIgMjdMMTIgMjhMMTAgMjhMMTAgMjdMOSAyN0w5IDI5TDEyIDI5TDEyIDMwTDExIDMwTDExIDMxTDEwIDMxTDEwIDMyTDEyIDMyTDEyIDMzTDEwIDMzTDEwIDM1TDkgMzVMOSAzNkwxMCAzNkwxMCAzNUwxMiAzNUwxMiAzNEwxMyAzNEwxMyAzMkwxNCAzMkwxNCAzM0wxNSAzM0wxNSAzMUwxOCAzMUwxOCAzM0wxOSAzM0wxOSAzNEwyMCAzNEwyMCAzNUwyMSAzNUwyMSAzNEwyMCAzNEwyMCAzM0wyMSAzM0wyMSAyOUwxOSAyOUwxOSAyOEwxNiAyOEwxNiAyNkwxNyAyNkwxNyAyN0wyMCAyN0wyMCAyNUwyMSAyNUwyMSAyNkwyMiAyNkwyMiAyOEwyMyAyOEwyMyAyOUwyNSAyOUwyNSAyN0wyNiAyN0wyNiAzMEwyOCAzMEwyOCAyNUwyNyAyNUwyNyAyNEwyOCAyNEwyOCAyM0wyOSAyM0wyOSAyNUwzMCAyNUwzMCAyMkwyOCAyMkwyOCAyMUwzMCAyMUwzMCAyMEwzMSAyMEwzMSAyMUwzMiAyMUwzMiAyMkwzMSAyMkwzMSAyM0wzMiAyM0wzMiAyMkwzMyAyMkwzMyAyMUwzMiAyMUwzMiAyMEwzMSAyMEwzMSAxOUwzMyAxOUwzMyAyMEwzNCAyMEwzNCAyMkwzNiAyMkwzNiAyMEwzNCAyMEwzNCAxOUwzNSAxOUwzNSAxOEwzNCAxOEwzNCAxNkwzNSAxNkwzNSAxN0wzNiAxN0wzNiAxNkwzNSAxNkwzNSAxNUwzNiAxNUwzNiAxMkwzNSAxMkwzNSAxMUwzNiAxMUwzNiA5TDM1IDlMMzUgMTFMMzQgMTFMMzQgMTBMMzMgMTBMMzMgOUwzMSA5TDMxIDEwTDMwIDEwTDMwIDExTDI5IDExTDI5IDEyTDMwIDEyTDMwIDExTDMxIDExTDMxIDEyTDMyIDEyTDMyIDE0TDMwIDE0TDMwIDE1TDMyIDE1TDMyIDE2TDMxIDE2TDMxIDE5TDMwIDE5TDMwIDE3TDI5IDE3TDI5IDE0TDI4IDE0TDI4IDEzTDI3IDEzTDI3IDEyTDI4IDEyTDI4IDEwTDI3IDEwTDI3IDlMMjUgOUwyNSAxMEwyNCAxMEwyNCA5TDIzIDlMMjMgOEwyMCA4TDIwIDlMMTkgOUwxOSA3TDIwIDdMMjAgNkwyMSA2TDIxIDdMMjIgN0wyMiA2TDIzIDZMMjMgN0wyNCA3TDI0IDhMMjcgOEwyNyA3TDI4IDdMMjggNkwyNyA2TDI3IDVMMjUgNUwyNSA0TDI0IDRMMjQgNUwyMyA1TDIzIDRMMjIgNEwyMiA2TDIxIDZMMjEgNEwxOSA0TDE5IDNaTTI0IDVMMjQgN0wyNSA3TDI1IDVaTTEwIDZMMTAgN0wxMSA3TDExIDZaTTEyIDZMMTIgOEwxMCA4TDEwIDlMOSA5TDkgMTFMNyAxMUw3IDEwTDYgMTBMNiAxMUw1IDExTDUgMTJMNiAxMkw2IDEzTDcgMTNMNyAxNEw2IDE0TDYgMTVMNyAxNUw3IDE0TDggMTRMOCAxNkw5IDE2TDkgMThMOCAxOEw4IDIwTDYgMjBMNiAyMUw3IDIxTDcgMjJMNiAyMkw2IDIzTDcgMjNMNyAyMkw4IDIyTDggMjFMOSAyMUw5IDIyTDExIDIyTDExIDIxTDEyIDIxTDEyIDIwTDEzIDIwTDEzIDE5TDE0IDE5TDE0IDE4TDE1IDE4TDE1IDE3TDE2IDE3TDE2IDE5TDE3IDE5TDE3IDIwTDE0IDIwTDE0IDIxTDEzIDIxTDEzIDIyTDEyIDIyTDEyIDIzTDExIDIzTDExIDI0TDEwIDI0TDEwIDI1TDExIDI1TDExIDI0TDEyIDI0TDEyIDIzTDEzIDIzTDEzIDI0TDE0IDI0TDE0IDIzTDE1IDIzTDE1IDIyTDE3IDIyTDE3IDIwTDE4IDIwTDE4IDE5TDE5IDE5TDE5IDIwTDIwIDIwTDIwIDIxTDE4IDIxTDE4IDIzTDIwIDIzTDIwIDIyTDIxIDIyTDIxIDIwTDIyIDIwTDIyIDE5TDIzIDE5TDIzIDE1TDI0IDE1TDI0IDE0TDI1IDE0TDI1IDE2TDI3IDE2TDI3IDE3TDI0IDE3TDI0IDE4TDI1IDE4TDI1IDE5TDI0IDE5TDI0IDIxTDI1IDIxTDI1IDIyTDI0IDIyTDI0IDI0TDI1IDI0TDI1IDI1TDI0IDI1TDI0IDI2TDIzIDI2TDIzIDI1TDIyIDI1TDIyIDI0TDIzIDI0TDIzIDIzTDIxIDIzTDIxIDI1TDIyIDI1TDIyIDI2TDIzIDI2TDIzIDI3TDI1IDI3TDI1IDI2TDI2IDI2TDI2IDI3TDI3IDI3TDI3IDI2TDI2IDI2TDI2IDIzTDI3IDIzTDI3IDIyTDI2IDIyTDI2IDIxTDI3IDIxTDI3IDIwTDI5IDIwTDI5IDE3TDI4IDE3TDI4IDE2TDI3IDE2TDI3IDE1TDI2IDE1TDI2IDE0TDI3IDE0TDI3IDEzTDI2IDEzTDI2IDExTDI3IDExTDI3IDEwTDI1IDEwTDI1IDExTDI0IDExTDI0IDE0TDIzIDE0TDIzIDEzTDIyIDEzTDIyIDE0TDIxIDE0TDIxIDEzTDE4IDEzTDE4IDEyTDIyIDEyTDIyIDExTDIxIDExTDIxIDEwTDIzIDEwTDIzIDlMMjAgOUwyMCAxMEwxOCAxMEwxOCA5TDE3IDlMMTcgMTBMMTYgMTBMMTYgMTFMMTIgMTFMMTIgMTJMMTMgMTJMMTMgMTNMMTQgMTNMMTQgMTRMMTEgMTRMMTEgOUwxMiA5TDEyIDhMMTMgOEwxMyAxMEwxNSAxMEwxNSA5TDE0IDlMMTQgOEwxMyA4TDEzIDZaTTE1IDZMMTUgN0wxNiA3TDE2IDZaTTI2IDZMMjYgN0wyNyA3TDI3IDZaTTE3IDEwTDE3IDEyTDE2IDEyTDE2IDEzTDE1IDEzTDE1IDE0TDE0IDE0TDE0IDE1TDE1IDE1TDE1IDE2TDE0IDE2TDE0IDE3TDE1IDE3TDE1IDE2TDE2IDE2TDE2IDE3TDE3IDE3TDE3IDE5TDE4IDE5TDE4IDE3TDE5IDE3TDE5IDE5TDIwIDE5TDIwIDIwTDIxIDIwTDIxIDE5TDIwIDE5TDIwIDE4TDIxIDE4TDIxIDE3TDIyIDE3TDIyIDE2TDIxIDE2TDIxIDE3TDE5IDE3TDE5IDE2TDE4IDE2TDE4IDE3TDE3IDE3TDE3IDE2TDE2IDE2TDE2IDE1TDE4IDE1TDE4IDE0TDE2IDE0TDE2IDEzTDE3IDEzTDE3IDEyTDE4IDEyTDE4IDEwWk0zMiAxMEwzMiAxMkwzNCAxMkwzNCAxMUwzMyAxMUwzMyAxMFpNMCAxMUwwIDEzTDEgMTNMMSAxMVpNNiAxMUw2IDEyTDcgMTJMNyAxMVpNOCAxM0w4IDE0TDkgMTRMOSAxNUwxMCAxNUwxMCAxM1pNMjUgMTNMMjUgMTRMMjYgMTRMMjYgMTNaTTAgMTRMMCAxNUwxIDE1TDEgMTRaTTMgMTRMMyAxNUw0IDE1TDQgMTRaTTE5IDE0TDE5IDE1TDIwIDE1TDIwIDE0Wk0yMiAxNEwyMiAxNUwyMyAxNUwyMyAxNFpNMzMgMTRMMzMgMTZMMzQgMTZMMzQgMTRaTTExIDE1TDExIDE2TDEwIDE2TDEwIDE5TDkgMTlMOSAyMEwxMCAyMEwxMCAyMUwxMSAyMUwxMSAxOUwxMiAxOUwxMiAxOEwxMyAxOEwxMyAxNkwxMiAxNkwxMiAxNVpNNiAxNkw2IDE3TDUgMTdMNSAxOUw3IDE5TDcgMThMNiAxOEw2IDE3TDcgMTdMNyAxNlpNMTEgMTdMMTEgMThMMTIgMThMMTIgMTdaTTI3IDE3TDI3IDE5TDI4IDE5TDI4IDE3Wk0zMiAxN0wzMiAxOEwzMyAxOEwzMyAxOUwzNCAxOUwzNCAxOEwzMyAxOEwzMyAxN1pNMjUgMTlMMjUgMjBMMjYgMjBMMjYgMTlaTTEgMjBMMSAyMUwyIDIxTDIgMjBaTTE0IDIxTDE0IDIyTDEzIDIyTDEzIDIzTDE0IDIzTDE0IDIyTDE1IDIyTDE1IDIxWk0xNiAyM0wxNiAyNEwxNyAyNEwxNyAyNUwxOCAyNUwxOCAyNkwxOSAyNkwxOSAyNUwyMCAyNUwyMCAyNEwxNyAyNEwxNyAyM1pNMTMgMjVMMTMgMjZMMTQgMjZMMTQgMjdMMTMgMjdMMTMgMjlMMTQgMjlMMTQgMzBMMTMgMzBMMTMgMzFMMTQgMzFMMTQgMzBMMTUgMzBMMTUgMjlMMTYgMjlMMTYgMzBMMTcgMzBMMTcgMjlMMTYgMjlMMTYgMjhMMTUgMjhMMTUgMjZMMTQgMjZMMTQgMjVaTTM1IDI1TDM1IDI3TDM2IDI3TDM2IDI1Wk0yOSAyNkwyOSAyN0wzMiAyN0wzMiAyNlpNNiAyN0w2IDI4TDcgMjhMNyAyN1pNMTQgMjhMMTQgMjlMMTUgMjlMMTUgMjhaTTE4IDI5TDE4IDMxTDE5IDMxTDE5IDMyTDIwIDMyTDIwIDMxTDE5IDMxTDE5IDI5Wk0yOSAyOUwyOSAzMkwzMiAzMkwzMiAyOVpNMzAgMzBMMzAgMzFMMzEgMzFMMzEgMzBaTTE0IDM0TDE0IDM2TDE1IDM2TDE1IDM1TDE2IDM1TDE2IDM0Wk0zNSAzNkwzNSAzN0wzNyAzN0wzNyAzNlpNMCAwTDAgN0w3IDdMNyAwWk0xIDFMMSA2TDYgNkw2IDFaTTIgMkwyIDVMNSA1TDUgMlpNMzAgMEwzMCA3TDM3IDdMMzcgMFpNMzEgMUwzMSA2TDM2IDZMMzYgMVpNMzIgMkwzMiA1TDM1IDVMMzUgMlpNMCAzMEwwIDM3TDcgMzdMNyAzMFpNMSAzMUwxIDM2TDYgMzZMNiAzMVpNMiAzMkwyIDM1TDUgMzVMNSAzMloiIGZpbGw9IiMwMDAwMDAiLz48L2c+PC9nPjwvc3ZnPgo=	completed	\N	\N	0.00	\N	\N	pra
29	13	\N	POS-2026-00013	shoaib	\N	500.00	percentage	15.00	75.00	16.00	68.00	493.00	cash	191963FCRN2514608496	100	submitted	14	2026-03-24 09:08:36	2026-03-24 09:25:14	c308a28bab7ff8475c9a92ad1c9eba71bbe45c6d6c8e702252765f299ac06f27	data:image/svg+xml;base64,PD94bWwgdmVyc2lvbj0iMS4wIiBlbmNvZGluZz0iVVRGLTgiPz4KPHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZlcnNpb249IjEuMSIgd2lkdGg9IjE1MCIgaGVpZ2h0PSIxNTAiIHZpZXdCb3g9IjAgMCAxNTAgMTUwIj48cmVjdCB4PSIwIiB5PSIwIiB3aWR0aD0iMTUwIiBoZWlnaHQ9IjE1MCIgZmlsbD0iI2ZmZmZmZiIvPjxnIHRyYW5zZm9ybT0ic2NhbGUoMy44NDYpIj48ZyB0cmFuc2Zvcm09InRyYW5zbGF0ZSgxLDEpIj48cGF0aCBmaWxsLXJ1bGU9ImV2ZW5vZGQiIGQ9Ik05IDBMOSAxTDggMUw4IDNMOSAzTDkgNEw4IDRMOCA4TDYgOEw2IDlMNCA5TDQgOEwzIDhMMyA5TDIgOUwyIDhMMCA4TDAgOUwxIDlMMSAxMEwzIDEwTDMgOUw0IDlMNCAxMUwyIDExTDIgMTNMMCAxM0wwIDE0TDMgMTRMMyAxNUw0IDE1TDQgMTRMMyAxNEwzIDEyTDUgMTJMNSAxM0w3IDEzTDcgMTRMNSAxNEw1IDE2TDYgMTZMNiAxN0w0IDE3TDQgMTlMNSAxOUw1IDIwTDQgMjBMNCAyMUw1IDIxTDUgMjBMNiAyMEw2IDIxTDcgMjFMNyAyMkw2IDIyTDYgMjNMNyAyM0w3IDI0TDUgMjRMNSAyNkw2IDI2TDYgMjdMNyAyN0w3IDI4TDUgMjhMNSAyN0wzIDI3TDMgMjZMMiAyNkwyIDI3TDEgMjdMMSAyNkwwIDI2TDAgMjdMMSAyN0wxIDI4TDAgMjhMMCAyOUwyIDI5TDIgMjhMNCAyOEw0IDI5TDggMjlMOCAzMUw5IDMxTDkgMzJMMTAgMzJMMTAgMzNMOCAzM0w4IDM0TDEwIDM0TDEwIDM1TDggMzVMOCAzN0w5IDM3TDkgMzZMMTAgMzZMMTAgMzVMMTEgMzVMMTEgMzNMMTIgMzNMMTIgMzFMMTEgMzFMMTEgMzBMMTIgMzBMMTIgMjlMMTEgMjlMMTEgMzBMMTAgMzBMMTAgMzFMOSAzMUw5IDI5TDEwIDI5TDEwIDI3TDkgMjdMOSAyOEw4IDI4TDggMjdMNyAyN0w3IDI2TDggMjZMOCAyNEw5IDI0TDkgMjVMMTAgMjVMMTAgMjRMMTQgMjRMMTQgMjNMMTUgMjNMMTUgMjJMMTcgMjJMMTcgMjBMMTggMjBMMTggMTlMMTkgMTlMMTkgMjBMMjAgMjBMMjAgMjFMMTggMjFMMTggMjNMMjAgMjNMMjAgMjJMMjEgMjJMMjEgMjBMMjIgMjBMMjIgMTlMMjMgMTlMMjMgMTVMMjQgMTVMMjQgMTRMMjUgMTRMMjUgMTZMMjcgMTZMMjcgMTdMMjQgMTdMMjQgMThMMjUgMThMMjUgMTlMMjQgMTlMMjQgMjFMMjUgMjFMMjUgMjJMMjQgMjJMMjQgMjRMMjUgMjRMMjUgMjVMMjQgMjVMMjQgMjZMMjMgMjZMMjMgMjVMMjIgMjVMMjIgMjRMMjMgMjRMMjMgMjNMMjEgMjNMMjEgMjVMMjAgMjVMMjAgMjRMMTcgMjRMMTcgMjNMMTYgMjNMMTYgMjRMMTcgMjRMMTcgMjVMMTggMjVMMTggMjZMMTkgMjZMMTkgMjVMMjAgMjVMMjAgMjdMMTcgMjdMMTcgMjZMMTYgMjZMMTYgMjhMMTUgMjhMMTUgMjlMMTQgMjlMMTQgMjZMMTUgMjZMMTUgMjVMMTQgMjVMMTQgMjZMMTEgMjZMMTEgMjhMMTMgMjhMMTMgMjlMMTQgMjlMMTQgMzBMMTMgMzBMMTMgMzFMMTQgMzFMMTQgMzBMMTUgMzBMMTUgMjlMMTYgMjlMMTYgMzBMMTcgMzBMMTcgMjlMMTYgMjlMMTYgMjhMMTkgMjhMMTkgMjlMMTggMjlMMTggMzFMMTUgMzFMMTUgMzNMMTQgMzNMMTQgMzJMMTMgMzJMMTMgMzRMMTQgMzRMMTQgMzVMMTIgMzVMMTIgMzZMMTMgMzZMMTMgMzdMMTUgMzdMMTUgMzZMMTYgMzZMMTYgMzdMMTcgMzdMMTcgMzZMMTYgMzZMMTYgMzVMMTkgMzVMMTkgMzdMMjIgMzdMMjIgMzZMMjMgMzZMMjMgMzRMMjEgMzRMMjEgMzNMMjIgMzNMMjIgMzJMMjMgMzJMMjMgMzFMMjIgMzFMMjIgMzBMMjUgMzBMMjUgMzFMMjQgMzFMMjQgMzVMMjUgMzVMMjUgMzdMMjYgMzdMMjYgMzNMMjcgMzNMMjcgMzJMMjUgMzJMMjUgMzFMMjggMzFMMjggMzRMMjcgMzRMMjcgMzdMMzAgMzdMMzAgMzVMMjkgMzVMMjkgMzNMMzIgMzNMMzIgMzVMMzEgMzVMMzEgMzZMMzIgMzZMMzIgMzdMMzQgMzdMMzQgMzZMMzMgMzZMMzMgMzJMMzUgMzJMMzUgMzNMMzQgMzNMMzQgMzVMMzcgMzVMMzcgMzNMMzYgMzNMMzYgMzFMMzcgMzFMMzcgMjlMMzYgMjlMMzYgMzBMMzUgMzBMMzUgMzFMMzQgMzFMMzQgMzBMMzMgMzBMMzMgMjhMMzIgMjhMMzIgMjdMMzUgMjdMMzUgMjhMMzQgMjhMMzQgMjlMMzUgMjlMMzUgMjhMMzYgMjhMMzYgMjdMMzcgMjdMMzcgMjRMMzUgMjRMMzUgMjVMMzQgMjVMMzQgMjZMMzIgMjZMMzIgMjVMMzEgMjVMMzEgMjRMMzMgMjRMMzMgMjJMMzQgMjJMMzQgMjNMMzcgMjNMMzcgMjBMMzYgMjBMMzYgMTlMMzcgMTlMMzcgMTZMMzYgMTZMMzYgMTVMMzcgMTVMMzcgOUwzNiA5TDM2IDhMMzQgOEwzNCA5TDMzIDlMMzMgOEwzMCA4TDMwIDlMMjkgOUwyOSA0TDI4IDRMMjggNUwyNyA1TDI3IDJMMjggMkwyOCAxTDI3IDFMMjcgMkwyNiAyTDI2IDFMMjUgMUwyNSAyTDI0IDJMMjQgMEwyMiAwTDIyIDJMMjQgMkwyNCAzTDIyIDNMMjIgNEwyMSA0TDIxIDNMMjAgM0wyMCAyTDE5IDJMMTkgMUwyMCAxTDIwIDBMMTkgMEwxOSAxTDE3IDFMMTcgMEwxNSAwTDE1IDJMMTQgMkwxNCAzTDE1IDNMMTUgMkwxNiAyTDE2IDRMMTUgNEwxNSA1TDEzIDVMMTMgM0wxMSAzTDExIDJMMTIgMkwxMiAxTDEzIDFMMTMgMEwxMiAwTDEyIDFMMTEgMUwxMSAwWk05IDFMOSAyTDExIDJMMTEgMVpNMTYgMUwxNiAyTDE3IDJMMTcgMVpNMjUgMkwyNSAzTDI2IDNMMjYgMlpNMTcgM0wxNyA0TDE2IDRMMTYgNUwxNyA1TDE3IDRMMTkgNEwxOSA1TDIwIDVMMjAgNkwxOSA2TDE5IDdMMTggN0wxOCA2TDE3IDZMMTcgN0wxOCA3TDE4IDhMMTQgOEwxNCA2TDEzIDZMMTMgN0wxMiA3TDEyIDVMMTEgNUwxMSA0TDkgNEw5IDVMMTAgNUwxMCA2TDkgNkw5IDdMMTAgN0wxMCA4TDggOEw4IDlMMTAgOUwxMCA4TDExIDhMMTEgMTNMMTAgMTNMMTAgMTBMOSAxMEw5IDEzTDEwIDEzTDEwIDE1TDExIDE1TDExIDE0TDE0IDE0TDE0IDE1TDE1IDE1TDE1IDE2TDE0IDE2TDE0IDE3TDE1IDE3TDE1IDE4TDE0IDE4TDE0IDE5TDEyIDE5TDEyIDE4TDEzIDE4TDEzIDE1TDEyIDE1TDEyIDE4TDcgMThMNyAxN0w5IDE3TDkgMTVMOCAxNUw4IDE0TDcgMTRMNyAxNUw2IDE1TDYgMTZMNyAxNkw3IDE3TDYgMTdMNiAxOEw1IDE4TDUgMTlMNiAxOUw2IDIwTDggMjBMOCAyM0wxMiAyM0wxMiAyMkwxMyAyMkwxMyAyM0wxNCAyM0wxNCAyMkwxNSAyMkwxNSAyMUwxNCAyMUwxNCAyMEwxNyAyMEwxNyAxOUwxOCAxOUwxOCAxN0wxOSAxN0wxOSAxOUwyMCAxOUwyMCAyMEwyMSAyMEwyMSAxOUwyMCAxOUwyMCAxOEwyMSAxOEwyMSAxN0wyMiAxN0wyMiAxNkwyMSAxNkwyMSAxN0wxOSAxN0wxOSAxNkwxOCAxNkwxOCAxN0wxNyAxN0wxNyAxNkwxNiAxNkwxNiAxNUwxOCAxNUwxOCAxNEwxNiAxNEwxNiAxM0wxNyAxM0wxNyAxMkwxOCAxMkwxOCAxM0wyMSAxM0wyMSAxNEwyMiAxNEwyMiAxNUwyMyAxNUwyMyAxNEwyNCAxNEwyNCAxMUwyNSAxMUwyNSAxMEwyNyAxMEwyNyAxMUwyNiAxMUwyNiAxM0wyNSAxM0wyNSAxNEwyNiAxNEwyNiAxNUwyNyAxNUwyNyAxNkwyOCAxNkwyOCAxN0wyNyAxN0wyNyAxOUwyOCAxOUwyOCAxN0wyOSAxN0wyOSAyMEwyNyAyMEwyNyAyMUwyNiAyMUwyNiAyMkwyNyAyMkwyNyAyM0wyNiAyM0wyNiAyNkwyNSAyNkwyNSAyN0wyMyAyN0wyMyAyNkwyMiAyNkwyMiAyNUwyMSAyNUwyMSAyNkwyMiAyNkwyMiAyOEwyMyAyOEwyMyAyOUwyNSAyOUwyNSAyN0wyNiAyN0wyNiAzMEwyOCAzMEwyOCAyNUwyNyAyNUwyNyAyNEwyOCAyNEwyOCAyM0wyOSAyM0wyOSAyNUwzMCAyNUwzMCAyMkwyOCAyMkwyOCAyMUwzMCAyMUwzMCAyMEwzMSAyMEwzMSAyMUwzMiAyMUwzMiAyMkwzMSAyMkwzMSAyM0wzMiAyM0wzMiAyMkwzMyAyMkwzMyAyMUwzMiAyMUwzMiAyMEwzMSAyMEwzMSAxOUwzMyAxOUwzMyAyMEwzNCAyMEwzNCAyMkwzNiAyMkwzNiAyMEwzNCAyMEwzNCAxOUwzNSAxOUwzNSAxOEwzNCAxOEwzNCAxNkwzNSAxNkwzNSAxN0wzNiAxN0wzNiAxNkwzNSAxNkwzNSAxNUwzNiAxNUwzNiAxMkwzNSAxMkwzNSAxMUwzNiAxMUwzNiA5TDM1IDlMMzUgMTFMMzQgMTFMMzQgMTBMMzMgMTBMMzMgOUwzMSA5TDMxIDEwTDMwIDEwTDMwIDExTDI5IDExTDI5IDEyTDMwIDEyTDMwIDExTDMxIDExTDMxIDEyTDMyIDEyTDMyIDE0TDMwIDE0TDMwIDE1TDMyIDE1TDMyIDE2TDMxIDE2TDMxIDE5TDMwIDE5TDMwIDE3TDI5IDE3TDI5IDE0TDI4IDE0TDI4IDEzTDI3IDEzTDI3IDEyTDI4IDEyTDI4IDEwTDI3IDEwTDI3IDlMMjUgOUwyNSAxMEwyNCAxMEwyNCA5TDIzIDlMMjMgOEwyMCA4TDIwIDlMMTkgOUwxOSA3TDIwIDdMMjAgNkwyMSA2TDIxIDdMMjIgN0wyMiA2TDIzIDZMMjMgN0wyNCA3TDI0IDhMMjcgOEwyNyA3TDI4IDdMMjggNkwyNyA2TDI3IDVMMjUgNUwyNSA0TDI0IDRMMjQgNUwyMyA1TDIzIDRMMjIgNEwyMiA2TDIxIDZMMjEgNEwxOSA0TDE5IDNaTTI0IDVMMjQgN0wyNSA3TDI1IDVaTTEwIDZMMTAgN0wxMSA3TDExIDhMMTIgOEwxMiA3TDExIDdMMTEgNlpNMTUgNkwxNSA3TDE2IDdMMTYgNlpNMjYgNkwyNiA3TDI3IDdMMjcgNlpNMTMgOEwxMyAxMEwxMiAxMEwxMiAxMkwxMyAxMkwxMyAxM0wxNCAxM0wxNCAxNEwxNSAxNEwxNSAxM0wxNiAxM0wxNiAxMkwxNyAxMkwxNyAxMEwxOCAxMEwxOCAxMkwyMiAxMkwyMiAxMUwyMSAxMUwyMSAxMEwyMyAxMEwyMyA5TDIwIDlMMjAgMTBMMTggMTBMMTggOUwxNyA5TDE3IDEwTDE2IDEwTDE2IDExTDEzIDExTDEzIDEwTDE1IDEwTDE1IDlMMTQgOUwxNCA4Wk02IDlMNiAxMEw1IDEwTDUgMTFMNiAxMUw2IDEyTDcgMTJMNyAxM0w4IDEzTDggMTJMNyAxMkw3IDExTDggMTFMOCAxMEw3IDEwTDcgOVpNNiAxMEw2IDExTDcgMTFMNyAxMFpNMzIgMTBMMzIgMTJMMzQgMTJMMzQgMTFMMzMgMTFMMzMgMTBaTTAgMTFMMCAxMkwxIDEyTDEgMTFaTTIyIDEzTDIyIDE0TDIzIDE0TDIzIDEzWk0yNiAxM0wyNiAxNEwyNyAxNEwyNyAxM1pNMTkgMTRMMTkgMTVMMjAgMTVMMjAgMTRaTTMzIDE0TDMzIDE2TDM0IDE2TDM0IDE0Wk0xIDE1TDEgMTZMMiAxNkwyIDE3TDEgMTdMMSAxOEwwIDE4TDAgMjBMMiAyMEwyIDE5TDMgMTlMMyAxOEwyIDE4TDIgMTdMMyAxN0wzIDE2TDIgMTZMMiAxNVpNNyAxNUw3IDE2TDggMTZMOCAxNVpNMTAgMTZMMTAgMTdMMTEgMTdMMTEgMTZaTTE1IDE2TDE1IDE3TDE2IDE3TDE2IDE5TDE3IDE5TDE3IDE3TDE2IDE3TDE2IDE2Wk0zMiAxN0wzMiAxOEwzMyAxOEwzMyAxOUwzNCAxOUwzNCAxOEwzMyAxOEwzMyAxN1pNMSAxOEwxIDE5TDIgMTlMMiAxOFpNNiAxOEw2IDE5TDcgMTlMNyAxOFpNOCAxOUw4IDIwTDkgMjBMOSAyMkwxMSAyMkwxMSAyMUwxMiAyMUwxMiAxOUwxMSAxOUwxMSAyMEw5IDIwTDkgMTlaTTI1IDE5TDI1IDIwTDI2IDIwTDI2IDE5Wk0xIDIxTDEgMjJMMCAyMkwwIDI0TDIgMjRMMiAyM0wzIDIzTDMgMjJMMiAyMkwyIDIxWk0xMyAyMUwxMyAyMkwxNCAyMkwxNCAyMVpNMSAyMkwxIDIzTDIgMjNMMiAyMlpNMyAyNEwzIDI1TDQgMjVMNCAyNFpNNiAyNUw2IDI2TDcgMjZMNyAyNVpNMzUgMjVMMzUgMjdMMzYgMjdMMzYgMjVaTTI2IDI2TDI2IDI3TDI3IDI3TDI3IDI2Wk0yOSAyNkwyOSAyN0wzMiAyN0wzMiAyNlpNMTkgMjlMMTkgMzFMMTggMzFMMTggMzNMMTkgMzNMMTkgMzRMMjAgMzRMMjAgMzVMMjEgMzVMMjEgMzRMMjAgMzRMMjAgMzNMMjEgMzNMMjEgMjlaTTI5IDI5TDI5IDMyTDMyIDMyTDMyIDI5Wk0zMCAzMEwzMCAzMUwzMSAzMUwzMSAzMFpNMTAgMzFMMTAgMzJMMTEgMzJMMTEgMzFaTTE5IDMxTDE5IDMyTDIwIDMyTDIwIDMxWk0xNSAzNEwxNSAzNUwxNiAzNUwxNiAzNFpNMzUgMzZMMzUgMzdMMzcgMzdMMzcgMzZaTTAgMEwwIDdMNyA3TDcgMFpNMSAxTDEgNkw2IDZMNiAxWk0yIDJMMiA1TDUgNUw1IDJaTTMwIDBMMzAgN0wzNyA3TDM3IDBaTTMxIDFMMzEgNkwzNiA2TDM2IDFaTTMyIDJMMzIgNUwzNSA1TDM1IDJaTTAgMzBMMCAzN0w3IDM3TDcgMzBaTTEgMzFMMSAzNkw2IDM2TDYgMzFaTTIgMzJMMiAzNUw1IDM1TDUgMzJaIiBmaWxsPSIjMDAwMDAwIi8+PC9nPjwvZz48L3N2Zz4K	completed	\N	\N	0.00	\N	\N	pra
27	13	\N	POS-2026-00012	shoaib	\N	3288.00	percentage	30.00	986.40	5.00	109.48	2411.08	credit_card	191963FCRL5557009892	100	submitted	13	2026-03-24 07:54:51	2026-03-24 07:55:57	7345f0fdb56d1a4569adb02cb70641e3bc932e6740947a10c568db8e89c0248f	data:image/svg+xml;base64,PD94bWwgdmVyc2lvbj0iMS4wIiBlbmNvZGluZz0iVVRGLTgiPz4KPHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZlcnNpb249IjEuMSIgd2lkdGg9IjE1MCIgaGVpZ2h0PSIxNTAiIHZpZXdCb3g9IjAgMCAxNTAgMTUwIj48cmVjdCB4PSIwIiB5PSIwIiB3aWR0aD0iMTUwIiBoZWlnaHQ9IjE1MCIgZmlsbD0iI2ZmZmZmZiIvPjxnIHRyYW5zZm9ybT0ic2NhbGUoMy44NDYpIj48ZyB0cmFuc2Zvcm09InRyYW5zbGF0ZSgxLDEpIj48cGF0aCBmaWxsLXJ1bGU9ImV2ZW5vZGQiIGQ9Ik0xMiAwTDEyIDJMMTEgMkwxMSAxTDEwIDFMMTAgMkwxMSAyTDExIDNMOCAzTDggNEw5IDRMOSA1TDggNUw4IDdMOSA3TDkgOEw2IDhMNiA5TDggOUw4IDEwTDkgMTBMOSAxMkw4IDEyTDggMTRMNiAxNEw2IDE1TDUgMTVMNSAxNEw0IDE0TDQgMTNMMyAxM0wzIDE0TDQgMTRMNCAxNUw1IDE1TDUgMTZMNCAxNkw0IDE5TDIgMTlMMiAyMEwxIDIwTDEgMjJMMCAyMkwwIDI0TDEgMjRMMSAyNUwwIDI1TDAgMjlMMSAyOUwxIDI2TDIgMjZMMiAyNEwzIDI0TDMgMjVMNCAyNUw0IDI0TDUgMjRMNSAyNUw3IDI1TDcgMjZMNCAyNkw0IDI3TDIgMjdMMiAyOUwzIDI5TDMgMjhMNCAyOEw0IDI3TDcgMjdMNyAyOEw2IDI4TDYgMjlMNyAyOUw3IDI4TDggMjhMOCAzMUw5IDMxTDkgMzJMOCAzMkw4IDM3TDkgMzdMOSAzNkwxMCAzNkwxMCAzNEwxMyAzNEwxMyAzM0wxNCAzM0wxNCAzNkwxMyAzNkwxMyAzN0wxNCAzN0wxNCAzNkwxNSAzNkwxNSAzN0wxNiAzN0wxNiAzNUwxOCAzNUwxOCAzNkwxOSAzNkwxOSAzN0wyMCAzN0wyMCAzNkwxOSAzNkwxOSAzNEwyMCAzNEwyMCAzNUwyMSAzNUwyMSAzNkwyMiAzNkwyMiAzN0wyMyAzN0wyMyAzNEwyNCAzNEwyNCAzNkwyNSAzNkwyNSAzN0wyNyAzN0wyNyAzNkwyOSAzNkwyOSAzN0wzMCAzN0wzMCAzNkwzMSAzNkwzMSAzNUwzMiAzNUwzMiAzNEwzMyAzNEwzMyAzNUwzNiAzNUwzNiAzNkwzNCAzNkwzNCAzN0wzNyAzN0wzNyAzNEwzNSAzNEwzNSAzMkwzNCAzMkwzNCAzMUwzNiAzMUwzNiAzMkwzNyAzMkwzNyAzMEwzNCAzMEwzNCAzMUwzMyAzMUwzMyAyOUwzNSAyOUwzNSAyOEwyOSAyOEwyOSAyN0wzMCAyN0wzMCAyNkwyOSAyNkwyOSAyN0wyOCAyN0wyOCAyNkwyNiAyNkwyNiAyNUwyNyAyNUwyNyAyM0wyOCAyM0wyOCAyNEwyOSAyNEwyOSAyM0wyOCAyM0wyOCAyMkwyOSAyMkwyOSAyMUwzMSAyMUwzMSAyMEwzMiAyMEwzMiAyMUwzMyAyMUwzMyAyM0wzNiAyM0wzNiAyNEwzMiAyNEwzMiAyNUwzMSAyNUwzMSAyNEwzMCAyNEwzMCAyNUwzMSAyNUwzMSAyNkwzMiAyNkwzMiAyN0wzMyAyN0wzMyAyNkwzMiAyNkwzMiAyNUwzNSAyNUwzNSAyN0wzNiAyN0wzNiAyOEwzNyAyOEwzNyAyNkwzNiAyNkwzNiAyNUwzNyAyNUwzNyAyMkwzNiAyMkwzNiAyMUwzNyAyMUwzNyAxOEwzNiAxOEwzNiAxN0wzNyAxN0wzNyAxNEwzNiAxNEwzNiAxM0wzNyAxM0wzNyAxMkwzNiAxMkwzNiAxMUwzNyAxMUwzNyAxMEwzNiAxMEwzNiA4TDM1IDhMMzUgOUwzNCA5TDM0IDhMMzMgOEwzMyAxMkwzMiAxMkwzMiA4TDMxIDhMMzEgMTJMMjkgMTJMMjkgMTFMMzAgMTFMMzAgMTBMMjkgMTBMMjkgOUwzMCA5TDMwIDhMMjkgOEwyOSA5TDI3IDlMMjcgOEwyOCA4TDI4IDdMMjkgN0wyOSA0TDI3IDRMMjcgM0wyOSAzTDI5IDBMMjYgMEwyNiAxTDI0IDFMMjQgMEwyMyAwTDIzIDFMMjQgMUwyNCAyTDIxIDJMMjEgM0wyMCAzTDIwIDJMMTggMkwxOCAzTDE2IDNMMTYgMkwxNyAyTDE3IDFMMTUgMUwxNSAwTDE0IDBMMTQgMUwxNSAxTDE1IDJMMTQgMkwxNCAzTDEzIDNMMTMgMFpNMTkgMEwxOSAxTDIyIDFMMjIgMFpNOCAxTDggMkw5IDJMOSAxWk0yNiAxTDI2IDJMMjUgMkwyNSAzTDIzIDNMMjMgNEwyMiA0TDIyIDNMMjEgM0wyMSA1TDIyIDVMMjIgNkwyMSA2TDIxIDdMMjIgN0wyMiA4TDIzIDhMMjMgOUwyNCA5TDI0IDEwTDIyIDEwTDIyIDEyTDIzIDEyTDIzIDEzTDIxIDEzTDIxIDE1TDIyIDE1TDIyIDE0TDIzIDE0TDIzIDE1TDI1IDE1TDI1IDE2TDIzIDE2TDIzIDE4TDE5IDE4TDE5IDE3TDIxIDE3TDIxIDE2TDIwIDE2TDIwIDE0TDE5IDE0TDE5IDEzTDIwIDEzTDIwIDEyTDIxIDEyTDIxIDEwTDE5IDEwTDE5IDExTDE4IDExTDE4IDlMMTkgOUwxOSA4TDE4IDhMMTggNkwxOSA2TDE5IDdMMjAgN0wyMCA1TDE4IDVMMTggNkwxNyA2TDE3IDdMMTYgN0wxNiA2TDE1IDZMMTUgNUwxNyA1TDE3IDRMMTYgNEwxNiAzTDE0IDNMMTQgNEwxNSA0TDE1IDVMMTEgNUwxMSA0TDEzIDRMMTMgM0wxMSAzTDExIDRMMTAgNEwxMCA2TDkgNkw5IDdMMTAgN0wxMCA2TDExIDZMMTEgOEwxMCA4TDEwIDlMOSA5TDkgMTBMMTAgMTBMMTAgMTJMOSAxMkw5IDE0TDggMTRMOCAxN0w2IDE3TDYgMTZMNyAxNkw3IDE1TDYgMTVMNiAxNkw1IDE2TDUgMThMOCAxOEw4IDE3TDkgMTdMOSAxNEwxMSAxNEwxMSAxNkwxMiAxNkwxMiAxN0wxMyAxN0wxMyAxOEwxNiAxOEwxNiAxOUwxMyAxOUwxMyAyMEwxMiAyMEwxMiAxOEwxMSAxOEwxMSAxN0wxMCAxN0wxMCAxOEw5IDE4TDkgMTlMNCAxOUw0IDIwTDkgMjBMOSAyMUwxMCAyMUwxMCAyM0w5IDIzTDkgMjJMNyAyMkw3IDIxTDYgMjFMNiAyMkw1IDIyTDUgMjNMNiAyM0w2IDI0TDcgMjRMNyAyNUw4IDI1TDggMjZMNyAyNkw3IDI3TDggMjdMOCAyOEw5IDI4TDkgMjZMMTAgMjZMMTAgMjhMMTEgMjhMMTEgMjlMMTQgMjlMMTQgMzBMMTEgMzBMMTEgMzFMMTAgMzFMMTAgMjlMOSAyOUw5IDMxTDEwIDMxTDEwIDMyTDkgMzJMOSAzM0wxMCAzM0wxMCAzMkwxMSAzMkwxMSAzM0wxMyAzM0wxMyAzMkwxNCAzMkwxNCAzM0wxNyAzM0wxNyAzNEwxOSAzNEwxOSAzM0wyMSAzM0wyMSAzNUwyMiAzNUwyMiAzNEwyMyAzNEwyMyAzM0wyMiAzM0wyMiAzMkwyMyAzMkwyMyAzMUwyNCAzMUwyNCAzMkwyOCAzMkwyOCAyOUwyNyAyOUwyNyAzMEwyNiAzMEwyNiAyOEwyOCAyOEwyOCAyN0wyNiAyN0wyNiAyNkwyNSAyNkwyNSAyNEwyNiAyNEwyNiAyM0wyNyAyM0wyNyAyMkwyOCAyMkwyOCAyMUwyNSAyMUwyNSAyM0wyNCAyM0wyNCAyMkwyMyAyMkwyMyAyMUwyMSAyMUwyMSAyMkwyMiAyMkwyMiAyM0wyMyAyM0wyMyAyNUwyMiAyNUwyMiAyNEwyMSAyNEwyMSAyN0wyMiAyN0wyMiAyOEwyMCAyOEwyMCAyNUwxOCAyNUwxOCAyN0wxNyAyN0wxNyAyNkwxNiAyNkwxNiAyNUwxNSAyNUwxNSAyNkwxNCAyNkwxNCAyM0wxNSAyM0wxNSAyNEwxNiAyNEwxNiAyM0wxNyAyM0wxNyAyNEwxOCAyNEwxOCAyMkwxOSAyMkwxOSAyM0wyMCAyM0wyMCAyMEwyMSAyMEwyMSAxOUwyNCAxOUwyNCAyMEwyNiAyMEwyNiAxOUwyNyAxOUwyNyAxOEwyOCAxOEwyOCAyMEwyOSAyMEwyOSAxOUwzMCAxOUwzMCAxOEwzMSAxOEwzMSAxOUwzMiAxOUwzMiAxOEwzMyAxOEwzMyAyMUwzNCAyMUwzNCAyMkwzNSAyMkwzNSAyMUwzNCAyMUwzNCAyMEwzNiAyMEwzNiAxOUwzNCAxOUwzNCAxN0wzMiAxN0wzMiAxOEwzMSAxOEwzMSAxN0wyOSAxN0wyOSAxNkwzMyAxNkwzMyAxNUwzNCAxNUwzNCAxNkwzNSAxNkwzNSAxN0wzNiAxN0wzNiAxNUwzNCAxNUwzNCAxM0wzMiAxM0wzMiAxNEwzMSAxNEwzMSAxM0wyOSAxM0wyOSAxMkwyOCAxMkwyOCAxMUwyNyAxMUwyNyA5TDI2IDlMMjYgMTBMMjUgMTBMMjUgOUwyNCA5TDI0IDZMMjUgNkwyNSA4TDI3IDhMMjcgN0wyOCA3TDI4IDZMMjcgNkwyNyA3TDI2IDdMMjYgNkwyNSA2TDI1IDVMMjcgNUwyNyA0TDI2IDRMMjYgMkwyNyAyTDI3IDFaTTE5IDNMMTkgNEwyMCA0TDIwIDNaTTIzIDRMMjMgNUwyNCA1TDI0IDRaTTEyIDZMMTIgOEwxMSA4TDExIDlMMTAgOUwxMCAxMEwxMiAxMEwxMiAxMkwxMCAxMkwxMCAxM0wxMSAxM0wxMSAxNEwxMyAxNEwxMyAxNUwxMiAxNUwxMiAxNkwxMyAxNkwxMyAxNUwxNCAxNUwxNCAxN0wxNSAxN0wxNSAxNUwxNiAxNUwxNiAxNkwxNyAxNkwxNyAxN0wxNiAxN0wxNiAxOEwxNyAxOEwxNyAxN0wxOCAxN0wxOCAxNkwxNyAxNkwxNyAxNUwxOSAxNUwxOSAxNEwxNSAxNEwxNSAxM0wxNCAxM0wxNCAxMkwxMyAxMkwxMyAxMUwxNSAxMUwxNSAxMkwxNiAxMkwxNiAxM0wxOCAxM0wxOCAxMkwxNyAxMkwxNyAxMEwxNSAxMEwxNSA5TDE4IDlMMTggOEwxNiA4TDE2IDdMMTUgN0wxNSA2TDE0IDZMMTQgOEwxMyA4TDEzIDZaTTIyIDZMMjIgN0wyMyA3TDIzIDZaTTAgOEwwIDEyTDEgMTJMMSAxM0wyIDEzTDIgMTJMMyAxMkwzIDExTDIgMTFMMiAxMkwxIDEyTDEgMTBMNSAxMEw1IDhaTTEyIDhMMTIgOUwxMyA5TDEzIDEwTDE0IDEwTDE0IDlMMTUgOUwxNSA4TDE0IDhMMTQgOUwxMyA5TDEzIDhaTTIwIDhMMjAgOUwyMSA5TDIxIDhaTTYgMTBMNiAxMUw0IDExTDQgMTJMNSAxMkw1IDEzTDcgMTNMNyAxMkw2IDEyTDYgMTFMNyAxMUw3IDEwWk0zNSAxMEwzNSAxMUwzNCAxMUwzNCAxMkwzNSAxMkwzNSAxM0wzNiAxM0wzNiAxMkwzNSAxMkwzNSAxMUwzNiAxMUwzNiAxMFpNMjYgMTFMMjYgMTJMMjQgMTJMMjQgMTNMMjMgMTNMMjMgMTRMMjQgMTRMMjQgMTNMMjYgMTNMMjYgMTRMMjUgMTRMMjUgMTVMMjYgMTVMMjYgMTZMMjUgMTZMMjUgMTdMMjcgMTdMMjcgMTVMMjkgMTVMMjkgMTRMMjcgMTRMMjcgMTFaTTEyIDEyTDEyIDEzTDEzIDEzTDEzIDE0TDE0IDE0TDE0IDE1TDE1IDE1TDE1IDE0TDE0IDE0TDE0IDEzTDEzIDEzTDEzIDEyWk0wIDE0TDAgMTVMMSAxNUwxIDE0Wk0yNiAxNEwyNiAxNUwyNyAxNUwyNyAxNFpNMiAxNUwyIDE2TDMgMTZMMyAxNVpNMCAxN0wwIDE5TDEgMTlMMSAxOEwzIDE4TDMgMTdaTTI4IDE3TDI4IDE4TDI5IDE4TDI5IDE3Wk0xMCAxOEwxMCAyMUwxMSAyMUwxMSAyMkwxMiAyMkwxMiAyNEwxMyAyNEwxMyAyM0wxNCAyM0wxNCAyMkwxMyAyMkwxMyAyMUwxMSAyMUwxMSAxOFpNMTggMThMMTggMjBMMTcgMjBMMTcgMTlMMTYgMTlMMTYgMjBMMTcgMjBMMTcgMjFMMTUgMjFMMTUgMjBMMTQgMjBMMTQgMjFMMTUgMjFMMTUgMjNMMTYgMjNMMTYgMjJMMTggMjJMMTggMjBMMjAgMjBMMjAgMTlMMTkgMTlMMTkgMThaTTI1IDE4TDI1IDE5TDI2IDE5TDI2IDE4Wk0yIDIxTDIgMjJMMyAyMkwzIDIxWk02IDIyTDYgMjNMNyAyM0w3IDI0TDggMjRMOCAyNUwxMSAyNUwxMSAyNkwxMyAyNkwxMyAyN0wxNCAyN0wxNCAyNkwxMyAyNkwxMyAyNUwxMSAyNUwxMSAyNEw5IDI0TDkgMjNMNyAyM0w3IDIyWk0zMCAyMkwzMCAyM0wzMiAyM0wzMiAyMlpNMSAyM0wxIDI0TDIgMjRMMiAyM1pNMjMgMjVMMjMgMjZMMjIgMjZMMjIgMjdMMjMgMjdMMjMgMjhMMjIgMjhMMjIgMjlMMTkgMjlMMTkgMzBMMTggMzBMMTggMzJMMjAgMzJMMjAgMzFMMjEgMzFMMjEgMzJMMjIgMzJMMjIgMzFMMjMgMzFMMjMgMzBMMjQgMzBMMjQgMjlMMjMgMjlMMjMgMjhMMjUgMjhMMjUgMjZMMjQgMjZMMjQgMjVaTTE1IDI2TDE1IDI3TDE2IDI3TDE2IDI4TDE3IDI4TDE3IDI3TDE2IDI3TDE2IDI2Wk0yMyAyNkwyMyAyN0wyNCAyN0wyNCAyNlpNMTEgMjdMMTEgMjhMMTIgMjhMMTIgMjdaTTE0IDI4TDE0IDI5TDE1IDI5TDE1IDI4Wk0xNiAyOUwxNiAzMEwxNCAzMEwxNCAzMUwxNSAzMUwxNSAzMkwxNiAzMkwxNiAzMUwxNyAzMUwxNyAyOVpNMjkgMjlMMjkgMzJMMzIgMzJMMzIgMjlaTTE5IDMwTDE5IDMxTDIwIDMxTDIwIDMwWk0yMSAzMEwyMSAzMUwyMiAzMUwyMiAzMFpNMjUgMzBMMjUgMzFMMjYgMzFMMjYgMzBaTTMwIDMwTDMwIDMxTDMxIDMxTDMxIDMwWk0yNCAzM0wyNCAzNEwyNSAzNEwyNSAzNkwyNiAzNkwyNiAzNEwyNyAzNEwyNyAzNUwyOCAzNUwyOCAzM1pNMzMgMzNMMzMgMzRMMzQgMzRMMzQgMzNaTTI5IDM0TDI5IDM1TDMxIDM1TDMxIDM0Wk0wIDBMMCA3TDcgN0w3IDBaTTEgMUwxIDZMNiA2TDYgMVpNMiAyTDIgNUw1IDVMNSAyWk0zMCAwTDMwIDdMMzcgN0wzNyAwWk0zMSAxTDMxIDZMMzYgNkwzNiAxWk0zMiAyTDMyIDVMMzUgNUwzNSAyWk0wIDMwTDAgMzdMNyAzN0w3IDMwWk0xIDMxTDEgMzZMNiAzNkw2IDMxWk0yIDMyTDIgMzVMNSAzNUw1IDMyWiIgZmlsbD0iIzAwMDAwMCIvPjwvZz48L2c+PC9zdmc+Cg==	completed	\N	\N	112.00	\N	\N	pra
28	13	\N	LOCAL-2026-00001	\N	\N	500.00	percentage	0.00	0.00	16.00	80.00	580.00	cash	\N	\N	local	13	2026-03-24 08:30:18	2026-03-24 08:30:18	17378345813d879f21803f9c2440cc2417614c7b0a1d316fedded515feff3ccc	\N	completed	\N	\N	0.00	\N	\N	local
\.


--
-- Data for Name: pra_logs; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.pra_logs (id, company_id, transaction_id, request_payload, response_payload, response_code, status, created_at, updated_at) FROM stdin;
1	13	15	{"InvoiceNumber":"","POSID":189587,"USIN":"POS-2026-00002","DateTime":"2026-03-09 13:04:05","BuyerName":"","BuyerPNTN":"","BuyerCNIC":"","BuyerPhoneNumber":"","TotalSaleValue":1000,"TotalQuantity":2,"TotalTaxCharged":160,"Discount":0,"FurtherTax":0,"TotalBillAmount":1160,"PaymentMode":1,"RefUSIN":null,"InvoiceType":1,"Items":[{"ItemCode":"0025","ItemName":"Chicken Broast","Quantity":2,"PCTCode":"00000000","TaxRate":16,"SaleValue":1000,"TotalAmount":1160,"TaxCharged":160,"Discount":0,"FurtherTax":0,"InvoiceType":1,"RefUSIN":null}]}	{"error":"cURL error 35: TLS connect error: error:0A000126:SSL routines::unexpected eof while reading (see https:\\/\\/curl.haxx.se\\/libcurl\\/c\\/libcurl-errors.html) for https:\\/\\/ims.pral.com.pk\\/ims\\/production\\/api\\/Live\\/PostData"}	500	failed	2026-03-09 13:05:10	2026-03-09 13:05:10
2	13	18	{"InvoiceNumber":"","POSID":189587,"USIN":"POS-2026-00005","DateTime":"2026-03-09 13:17:50","BuyerName":"","BuyerPNTN":"","BuyerCNIC":"","BuyerPhoneNumber":"","TotalSaleValue":750,"TotalQuantity":1,"TotalTaxCharged":120,"Discount":0,"FurtherTax":0,"TotalBillAmount":870,"PaymentMode":1,"RefUSIN":null,"InvoiceType":1,"Items":[{"ItemCode":"0026","ItemName":"Broast Deal","Quantity":1,"PCTCode":"00000000","TaxRate":16,"SaleValue":750,"TotalAmount":870,"TaxCharged":120,"Discount":0,"FurtherTax":0,"InvoiceType":1,"RefUSIN":null}]}	{"error":"cURL error 35: TLS connect error: error:0A000126:SSL routines::unexpected eof while reading (see https:\\/\\/curl.haxx.se\\/libcurl\\/c\\/libcurl-errors.html) for https:\\/\\/ims.pral.com.pk\\/ims\\/sandbox\\/api\\/Live\\/PostData"}	500	failed	2026-03-09 13:17:52	2026-03-09 13:17:53
3	13	19	{"InvoiceNumber":"","POSID":189587,"USIN":"POS-2026-00006","DateTime":"2026-03-19 08:36:02","BuyerName":"","BuyerPNTN":"","BuyerCNIC":"","BuyerPhoneNumber":"","TotalSaleValue":160,"TotalQuantity":10,"TotalTaxCharged":21.5,"Discount":25.6,"FurtherTax":0,"TotalBillAmount":155.9,"PaymentMode":1,"RefUSIN":null,"InvoiceType":1,"Items":[{"ItemCode":"IT_0001","ItemName":"roti","Quantity":10,"PCTCode":"00000000","TaxRate":16,"SaleValue":134.4,"TotalAmount":155.9,"TaxCharged":21.5,"Discount":25.6,"FurtherTax":0,"InvoiceType":1,"RefUSIN":null}]}	{"error":"cURL error 35: TLS connect error: error:0A000126:SSL routines::unexpected eof while reading (see https:\\/\\/curl.haxx.se\\/libcurl\\/c\\/libcurl-errors.html) for https:\\/\\/ims.pral.com.pk\\/ims\\/sandbox\\/api\\/Live\\/PostData"}	500	failed	2026-03-19 08:36:43	2026-03-19 08:36:43
4	13	19	{"InvoiceNumber":"","POSID":189587,"USIN":"POS-2026-00006","DateTime":"2026-03-19 08:36:02","BuyerName":"","BuyerPNTN":"","BuyerCNIC":"","BuyerPhoneNumber":"","TotalSaleValue":160,"TotalQuantity":10,"TotalTaxCharged":21.5,"Discount":25.6,"FurtherTax":0,"TotalBillAmount":155.9,"PaymentMode":1,"RefUSIN":null,"InvoiceType":1,"Items":[{"ItemCode":"IT_0001","ItemName":"roti","Quantity":10,"PCTCode":"00000000","TaxRate":16,"SaleValue":134.4,"TotalAmount":155.9,"TaxCharged":21.5,"Discount":25.6,"FurtherTax":0,"InvoiceType":1,"RefUSIN":null}]}	{"error":"cURL error 35: TLS connect error: error:0A000126:SSL routines::unexpected eof while reading (see https:\\/\\/curl.haxx.se\\/libcurl\\/c\\/libcurl-errors.html) for https:\\/\\/ims.pral.com.pk\\/ims\\/sandbox\\/api\\/Live\\/PostData"}	500	failed	2026-03-19 08:36:50	2026-03-19 08:36:51
5	13	20	{"InvoiceNumber":"","POSID":189587,"USIN":"POS-2026-00007","DateTime":"2026-03-19 08:51:07","BuyerName":"","BuyerPNTN":"","BuyerCNIC":"","BuyerPhoneNumber":"","TotalSaleValue":750,"TotalQuantity":1,"TotalTaxCharged":28.13,"Discount":187.5,"FurtherTax":0,"TotalBillAmount":590.63,"PaymentMode":2,"RefUSIN":null,"InvoiceType":1,"Items":[{"ItemCode":"0026","ItemName":"Broast Deal","Quantity":1,"PCTCode":"00000000","TaxRate":5,"SaleValue":562.5,"TotalAmount":590.63,"TaxCharged":28.13,"Discount":187.5,"FurtherTax":0,"InvoiceType":1,"RefUSIN":null}]}	{"error":"cURL error 35: TLS connect error: error:0A000126:SSL routines::unexpected eof while reading (see https:\\/\\/curl.haxx.se\\/libcurl\\/c\\/libcurl-errors.html) for https:\\/\\/ims.pral.com.pk\\/ims\\/sandbox\\/api\\/Live\\/PostData"}	500	failed	2026-03-19 08:51:08	2026-03-19 08:51:08
6	13	20	{"InvoiceNumber":"","POSID":189587,"USIN":"POS-2026-00007","DateTime":"2026-03-19 08:51:07","BuyerName":"","BuyerPNTN":"","BuyerCNIC":"","BuyerPhoneNumber":"","TotalSaleValue":750,"TotalQuantity":1,"TotalTaxCharged":28.13,"Discount":187.5,"FurtherTax":0,"TotalBillAmount":590.63,"PaymentMode":2,"RefUSIN":null,"InvoiceType":1,"Items":[{"ItemCode":"0026","ItemName":"Broast Deal","Quantity":1,"PCTCode":"00000000","TaxRate":5,"SaleValue":562.5,"TotalAmount":590.63,"TaxCharged":28.13,"Discount":187.5,"FurtherTax":0,"InvoiceType":1,"RefUSIN":null}]}	{"error":"cURL error 35: TLS connect error: error:0A000126:SSL routines::unexpected eof while reading (see https:\\/\\/curl.haxx.se\\/libcurl\\/c\\/libcurl-errors.html) for https:\\/\\/ims.pral.com.pk\\/ims\\/sandbox\\/api\\/Live\\/PostData"}	500	failed	2026-03-19 08:54:40	2026-03-19 08:54:41
7	13	20	{"InvoiceNumber":"","POSID":191963,"USIN":"POS-2026-00007","DateTime":"2026-03-19 08:51:07","BuyerName":"","BuyerPNTN":"","BuyerCNIC":"","BuyerPhoneNumber":"","TotalSaleValue":750,"TotalQuantity":1,"TotalTaxCharged":28.13,"Discount":187.5,"FurtherTax":0,"TotalBillAmount":590.63,"PaymentMode":2,"RefUSIN":null,"InvoiceType":1,"Items":[{"ItemCode":"0026","ItemName":"Broast Deal","Quantity":1,"PCTCode":"00000000","TaxRate":5,"SaleValue":562.5,"TotalAmount":590.63,"TaxCharged":28.13,"Discount":187.5,"FurtherTax":0,"InvoiceType":1,"RefUSIN":null}]}	{"error":"cURL error 35: TLS connect error: error:0A000126:SSL routines::unexpected eof while reading (see https:\\/\\/curl.haxx.se\\/libcurl\\/c\\/libcurl-errors.html) for https:\\/\\/ims.pral.com.pk\\/ims\\/sandbox\\/api\\/Live\\/PostData"}	500	failed	2026-03-19 09:26:17	2026-03-19 09:26:18
8	13	20	{"InvoiceNumber":"","POSID":191963,"USIN":"POS-2026-00007","DateTime":"2026-03-19 08:51:07","BuyerName":"","BuyerPNTN":"","BuyerCNIC":"","BuyerPhoneNumber":"","TotalSaleValue":750,"TotalQuantity":1,"TotalTaxCharged":28.13,"Discount":187.5,"FurtherTax":0,"TotalBillAmount":590.63,"PaymentMode":2,"RefUSIN":null,"InvoiceType":1,"Items":[{"ItemCode":"0026","ItemName":"Broast Deal","Quantity":1,"PCTCode":"00000000","TaxRate":5,"SaleValue":562.5,"TotalAmount":590.63,"TaxCharged":28.13,"Discount":187.5,"FurtherTax":0,"InvoiceType":1,"RefUSIN":null}]}	{"error":"cURL error 35: TLS connect error: error:0A000126:SSL routines::unexpected eof while reading (see https:\\/\\/curl.haxx.se\\/libcurl\\/c\\/libcurl-errors.html) for https:\\/\\/ims.pral.com.pk\\/ims\\/production\\/api\\/Live\\/PostData"}	500	failed	2026-03-19 09:27:03	2026-03-19 09:27:03
9	13	15	{"InvoiceNumber":"","POSID":191963,"USIN":"POS-2026-00002","DateTime":"2026-03-09T13:04:05","BuyerName":"Customer","BuyerPNTN":"","BuyerCNIC":"","BuyerPhoneNumber":"","TotalSaleValue":1000,"TotalQuantity":2,"TotalTaxCharged":160,"Discount":0,"FurtherTax":0,"TotalBillAmount":1160,"PaymentMode":1,"RefUSIN":null,"InvoiceType":1,"Items":[{"ItemCode":"0025","ItemName":"Chicken Broast","Quantity":2,"PCTCode":"00000000","TaxRate":16,"SaleValue":1000,"TotalAmount":1160,"TaxCharged":160,"Discount":0,"FurtherTax":0,"InvoiceType":1,"RefUSIN":null}]}	{"InvoiceNumber":"Not Available","Code":"402","Response":"Fiscal invoice creation failed.","Errors":"Invoice validation failed: Item 1: Total mismatch. Expected 2320.00, got 1160.00\\r\\nItem 1: Tax mismatch. Expected 320.00, got 160.00\\r\\nTotal sale value mismatch: Invoice total 1000.00 vs Sum of items 2000.00\\r\\nInvoice total calculation error: Bill Amount 1160.00 does not equal (Sale Value 2000.00 + Tax 160.00 - Discount 0.00)"}	402	failed	2026-03-19 09:54:44	2026-03-19 09:54:45
10	13	18	{"InvoiceNumber":"","POSID":191963,"USIN":"POS-2026-00005","DateTime":"2026-03-09T13:17:50","BuyerName":"Customer","BuyerPNTN":"","BuyerCNIC":"","BuyerPhoneNumber":"","TotalSaleValue":750,"TotalQuantity":1,"TotalTaxCharged":120,"Discount":0,"FurtherTax":0,"TotalBillAmount":870,"PaymentMode":1,"RefUSIN":null,"InvoiceType":1,"Items":[{"ItemCode":"0026","ItemName":"Broast Deal","Quantity":1,"PCTCode":"00000000","TaxRate":16,"SaleValue":750,"TotalAmount":870,"TaxCharged":120,"Discount":0,"FurtherTax":0,"InvoiceType":1,"RefUSIN":null}]}	{"InvoiceNumber":"191963FCMN5445803834","Code":"100","Response":"Fiscal Invoice Number generated successfully.","Errors":null}	100	success	2026-03-19 09:54:45	2026-03-19 09:54:45
11	13	19	{"InvoiceNumber":"","POSID":191963,"USIN":"POS-2026-00006","DateTime":"2026-03-19T08:36:02","BuyerName":"Customer","BuyerPNTN":"","BuyerCNIC":"","BuyerPhoneNumber":"","TotalSaleValue":160,"TotalQuantity":10,"TotalTaxCharged":21.5,"Discount":25.6,"FurtherTax":0,"TotalBillAmount":155.9,"PaymentMode":1,"RefUSIN":null,"InvoiceType":1,"Items":[{"ItemCode":"IT_0001","ItemName":"roti","Quantity":10,"PCTCode":"00000000","TaxRate":16,"SaleValue":134.4,"TotalAmount":155.9,"TaxCharged":21.5,"Discount":25.6,"FurtherTax":0,"InvoiceType":1,"RefUSIN":null}]}	{"InvoiceNumber":"Not Available","Code":"402","Response":"Fiscal invoice creation failed.","Errors":"Invoice validation failed: Item 1: Total mismatch. Expected 1533.44, got 155.90\\r\\nItem 1: Tax mismatch. Expected 215.04, got 21.50\\r\\nTotal sale value mismatch: Invoice total 160.00 vs Sum of items 1344.00\\r\\nInvoice total calculation error: Bill Amount 155.90 does not equal (Sale Value 1344.00 + Tax 21.50 - Discount 25.60)"}	402	failed	2026-03-19 09:54:46	2026-03-19 09:54:46
12	13	20	{"InvoiceNumber":"","POSID":191963,"USIN":"POS-2026-00007","DateTime":"2026-03-19T08:51:07","BuyerName":"Customer","BuyerPNTN":"","BuyerCNIC":"","BuyerPhoneNumber":"","TotalSaleValue":750,"TotalQuantity":1,"TotalTaxCharged":28.13,"Discount":187.5,"FurtherTax":0,"TotalBillAmount":590.63,"PaymentMode":2,"RefUSIN":null,"InvoiceType":1,"Items":[{"ItemCode":"0026","ItemName":"Broast Deal","Quantity":1,"PCTCode":"00000000","TaxRate":5,"SaleValue":562.5,"TotalAmount":590.63,"TaxCharged":28.13,"Discount":187.5,"FurtherTax":0,"InvoiceType":1,"RefUSIN":null}]}	{"InvoiceNumber":"Not Available","Code":"402","Response":"Fiscal invoice creation failed.","Errors":"Invoice validation failed: Item 1: Total mismatch. Expected 403.12, got 590.63\\r\\nTotal sale value mismatch: Invoice total 750.00 vs Sum of items 562.50\\r\\nInvoice total calculation error: Bill Amount 590.63 does not equal (Sale Value 562.50 + Tax 28.13 - Discount 187.50)"}	402	failed	2026-03-19 09:54:46	2026-03-19 09:54:47
13	13	21	{"InvoiceNumber":"","POSID":191963,"USIN":"POS-2026-00008","DateTime":"2026-03-19T09:54:09","BuyerName":"jaafir","BuyerPNTN":"","BuyerCNIC":"","BuyerPhoneNumber":"","TotalSaleValue":530,"TotalQuantity":12,"TotalTaxCharged":22.53,"Discount":79.5,"FurtherTax":0,"TotalBillAmount":473.03,"PaymentMode":2,"RefUSIN":null,"InvoiceType":1,"Items":[{"ItemCode":"IT_0001","ItemName":"Daal","Quantity":1,"PCTCode":"00000000","TaxRate":5,"SaleValue":153,"TotalAmount":160.65,"TaxCharged":7.65,"Discount":27,"FurtherTax":0,"InvoiceType":1,"RefUSIN":null},{"ItemCode":"IT_0002","ItemName":"roti","Quantity":10,"PCTCode":"00000000","TaxRate":5,"SaleValue":0,"TotalAmount":0,"TaxCharged":0,"Discount":0,"FurtherTax":0,"InvoiceType":1,"RefUSIN":null},{"ItemCode":"IT_0003","ItemName":"rice","Quantity":1,"PCTCode":"00000000","TaxRate":5,"SaleValue":297.5,"TotalAmount":312.38,"TaxCharged":14.88,"Discount":52.5,"FurtherTax":0,"InvoiceType":1,"RefUSIN":null}]}	{"InvoiceNumber":"Not Available","Code":"402","Response":"Fiscal invoice creation failed.","Errors":"Invoice validation failed: Item 1: Total mismatch. Expected 133.65, got 160.65\\r\\nItem 2: Unit price is required and must be greater than zero\\r\\nItem 2: Total amount must be greater than zero\\r\\nItem 3: Total mismatch. Expected 259.88, got 312.38\\r\\nTotal sale value mismatch: Invoice total 530.00 vs Sum of items 450.50\\r\\nInvoice total calculation error: Bill Amount 473.03 does not equal (Sale Value 450.50 + Tax 22.53 - Discount 79.50)"}	402	failed	2026-03-19 09:55:16	2026-03-19 09:55:17
14	13	21	{"InvoiceNumber":"","POSID":191963,"USIN":"POS-2026-00008","DateTime":"2026-03-19T09:54:09","BuyerName":"jaafir","BuyerPNTN":"","BuyerCNIC":"","BuyerPhoneNumber":"","TotalSaleValue":530,"TotalQuantity":12,"TotalTaxCharged":22.53,"Discount":79.5,"FurtherTax":0,"TotalBillAmount":473.03,"PaymentMode":2,"RefUSIN":null,"InvoiceType":1,"Items":[{"ItemCode":"IT_0001","ItemName":"Daal","Quantity":1,"PCTCode":"00000000","TaxRate":5,"SaleValue":153,"TotalAmount":160.65,"TaxCharged":7.65,"Discount":27,"FurtherTax":0,"InvoiceType":1,"RefUSIN":null},{"ItemCode":"IT_0002","ItemName":"roti","Quantity":10,"PCTCode":"00000000","TaxRate":5,"SaleValue":0,"TotalAmount":0,"TaxCharged":0,"Discount":0,"FurtherTax":0,"InvoiceType":1,"RefUSIN":null},{"ItemCode":"IT_0003","ItemName":"rice","Quantity":1,"PCTCode":"00000000","TaxRate":5,"SaleValue":297.5,"TotalAmount":312.38,"TaxCharged":14.88,"Discount":52.5,"FurtherTax":0,"InvoiceType":1,"RefUSIN":null}]}	{"InvoiceNumber":"Not Available","Code":"402","Response":"Fiscal invoice creation failed.","Errors":"Invoice validation failed: Item 1: Total mismatch. Expected 133.65, got 160.65\\r\\nItem 2: Unit price is required and must be greater than zero\\r\\nItem 2: Total amount must be greater than zero\\r\\nItem 3: Total mismatch. Expected 259.88, got 312.38\\r\\nTotal sale value mismatch: Invoice total 530.00 vs Sum of items 450.50\\r\\nInvoice total calculation error: Bill Amount 473.03 does not equal (Sale Value 450.50 + Tax 22.53 - Discount 79.50)"}	402	failed	2026-03-19 09:55:23	2026-03-19 09:55:23
15	13	21	{"InvoiceNumber":"","POSID":191963,"USIN":"POS-2026-00008","DateTime":"2026-03-19T09:54:09","BuyerName":"jaafir","BuyerPNTN":"","BuyerCNIC":"","BuyerPhoneNumber":"","TotalSaleValue":530,"TotalQuantity":12,"TotalTaxCharged":22.53,"Discount":79.5,"FurtherTax":0,"TotalBillAmount":473.03,"PaymentMode":2,"RefUSIN":null,"InvoiceType":1,"Items":[{"ItemCode":"IT_0001","ItemName":"Daal","Quantity":1,"PCTCode":"00000000","TaxRate":5,"SaleValue":153,"TotalAmount":160.65,"TaxCharged":7.65,"Discount":27,"FurtherTax":0,"InvoiceType":1,"RefUSIN":null},{"ItemCode":"IT_0002","ItemName":"roti","Quantity":10,"PCTCode":"00000000","TaxRate":5,"SaleValue":0,"TotalAmount":0,"TaxCharged":0,"Discount":0,"FurtherTax":0,"InvoiceType":1,"RefUSIN":null},{"ItemCode":"IT_0003","ItemName":"rice","Quantity":1,"PCTCode":"00000000","TaxRate":5,"SaleValue":297.5,"TotalAmount":312.38,"TaxCharged":14.88,"Discount":52.5,"FurtherTax":0,"InvoiceType":1,"RefUSIN":null}]}	{"InvoiceNumber":"Not Available","Code":"402","Response":"Fiscal invoice creation failed.","Errors":"Invoice validation failed: Item 1: Total mismatch. Expected 133.65, got 160.65\\r\\nItem 2: Unit price is required and must be greater than zero\\r\\nItem 2: Total amount must be greater than zero\\r\\nItem 3: Total mismatch. Expected 259.88, got 312.38\\r\\nTotal sale value mismatch: Invoice total 530.00 vs Sum of items 450.50\\r\\nInvoice total calculation error: Bill Amount 473.03 does not equal (Sale Value 450.50 + Tax 22.53 - Discount 79.50)"}	402	failed	2026-03-19 09:55:30	2026-03-19 09:55:30
16	13	21	{"InvoiceNumber":"","POSID":191963,"USIN":"POS-2026-00008","DateTime":"2026-03-19T09:54:09","BuyerName":"jaafir","BuyerPNTN":"","BuyerCNIC":"","BuyerPhoneNumber":"","TotalSaleValue":530,"TotalQuantity":12,"TotalTaxCharged":22.53,"Discount":79.5,"FurtherTax":0,"TotalBillAmount":473.03,"PaymentMode":2,"RefUSIN":null,"InvoiceType":1,"Items":[{"ItemCode":"IT_0001","ItemName":"Daal","Quantity":1,"PCTCode":"00000000","TaxRate":5,"SaleValue":153,"TotalAmount":160.65,"TaxCharged":7.65,"Discount":27,"FurtherTax":0,"InvoiceType":1,"RefUSIN":null},{"ItemCode":"IT_0002","ItemName":"roti","Quantity":10,"PCTCode":"00000000","TaxRate":5,"SaleValue":0,"TotalAmount":0,"TaxCharged":0,"Discount":0,"FurtherTax":0,"InvoiceType":1,"RefUSIN":null},{"ItemCode":"IT_0003","ItemName":"rice","Quantity":1,"PCTCode":"00000000","TaxRate":5,"SaleValue":297.5,"TotalAmount":312.38,"TaxCharged":14.88,"Discount":52.5,"FurtherTax":0,"InvoiceType":1,"RefUSIN":null}]}	{"InvoiceNumber":"Not Available","Code":"402","Response":"Fiscal invoice creation failed.","Errors":"Invoice validation failed: Item 1: Total mismatch. Expected 133.65, got 160.65\\r\\nItem 2: Unit price is required and must be greater than zero\\r\\nItem 2: Total amount must be greater than zero\\r\\nItem 3: Total mismatch. Expected 259.88, got 312.38\\r\\nTotal sale value mismatch: Invoice total 530.00 vs Sum of items 450.50\\r\\nInvoice total calculation error: Bill Amount 473.03 does not equal (Sale Value 450.50 + Tax 22.53 - Discount 79.50)"}	402	failed	2026-03-19 09:55:53	2026-03-19 09:55:54
17	13	15	{"InvoiceNumber":"","POSID":191963,"USIN":"POS-2026-00002","DateTime":"2026-03-09T13:04:05","BuyerName":"Customer","BuyerPNTN":"","BuyerCNIC":"","BuyerPhoneNumber":"","TotalSaleValue":1000,"TotalQuantity":2,"TotalTaxCharged":160,"Discount":0,"FurtherTax":0,"TotalBillAmount":1160,"PaymentMode":1,"RefUSIN":null,"InvoiceType":1,"Items":[{"ItemCode":"0025","ItemName":"Chicken Broast","Quantity":2,"PCTCode":"00000000","TaxRate":16,"SaleValue":500,"TotalAmount":1160,"TaxCharged":160,"Discount":0,"FurtherTax":0,"InvoiceType":1,"RefUSIN":null}]}	{"InvoiceNumber":"191963FCMN5630246564","Code":"100","Response":"Fiscal Invoice Number generated successfully.","Errors":null}	100	success	2026-03-19 09:56:30	2026-03-19 09:56:31
18	13	19	{"InvoiceNumber":"","POSID":191963,"USIN":"POS-2026-00006","DateTime":"2026-03-19T08:36:02","BuyerName":"Customer","BuyerPNTN":"","BuyerCNIC":"","BuyerPhoneNumber":"","TotalSaleValue":134.4,"TotalQuantity":10,"TotalTaxCharged":21.5,"Discount":25.6,"FurtherTax":0,"TotalBillAmount":130.3,"PaymentMode":1,"RefUSIN":null,"InvoiceType":1,"Items":[{"ItemCode":"IT_0001","ItemName":"roti","Quantity":10,"PCTCode":"00000000","TaxRate":16,"SaleValue":13.44,"TotalAmount":155.9,"TaxCharged":21.5,"Discount":25.6,"FurtherTax":0,"InvoiceType":1,"RefUSIN":null}]}	{"InvoiceNumber":"Not Available","Code":"402","Response":"Fiscal invoice creation failed.","Errors":"Invoice validation failed: Item 1: Total mismatch. Expected 130.30, got 155.90\\r\\nTotal bill amount mismatch: Invoice total 130.30 vs Sum of items 155.90"}	402	failed	2026-03-19 09:56:31	2026-03-19 09:56:31
19	13	20	{"InvoiceNumber":"","POSID":191963,"USIN":"POS-2026-00007","DateTime":"2026-03-19T08:51:07","BuyerName":"Customer","BuyerPNTN":"","BuyerCNIC":"","BuyerPhoneNumber":"","TotalSaleValue":562.5,"TotalQuantity":1,"TotalTaxCharged":28.13,"Discount":187.5,"FurtherTax":0,"TotalBillAmount":403.13,"PaymentMode":2,"RefUSIN":null,"InvoiceType":1,"Items":[{"ItemCode":"0026","ItemName":"Broast Deal","Quantity":1,"PCTCode":"00000000","TaxRate":5,"SaleValue":562.5,"TotalAmount":590.63,"TaxCharged":28.13,"Discount":187.5,"FurtherTax":0,"InvoiceType":1,"RefUSIN":null}]}	{"InvoiceNumber":"Not Available","Code":"402","Response":"Fiscal invoice creation failed.","Errors":"Invoice validation failed: Item 1: Total mismatch. Expected 403.12, got 590.63\\r\\nTotal bill amount mismatch: Invoice total 403.13 vs Sum of items 590.63"}	402	failed	2026-03-19 09:56:31	2026-03-19 09:56:32
20	13	19	{"InvoiceNumber":"","POSID":191963,"USIN":"POS-2026-00006","DateTime":"2026-03-19T08:36:02","BuyerName":"Customer","BuyerPNTN":"","BuyerCNIC":"","BuyerPhoneNumber":"","TotalSaleValue":134.4,"TotalQuantity":10,"TotalTaxCharged":21.5,"Discount":0,"FurtherTax":0,"TotalBillAmount":155.9,"PaymentMode":1,"RefUSIN":null,"InvoiceType":1,"Items":[{"ItemCode":"IT_0001","ItemName":"roti","Quantity":10,"PCTCode":"00000000","TaxRate":16,"SaleValue":13.44,"TotalAmount":155.9,"TaxCharged":21.5,"Discount":0,"FurtherTax":0,"InvoiceType":1,"RefUSIN":null}]}	{"InvoiceNumber":"191963FCMN583149109","Code":"100","Response":"Fiscal Invoice Number generated successfully.","Errors":null}	100	success	2026-03-19 09:58:02	2026-03-19 09:58:03
21	13	20	{"InvoiceNumber":"","POSID":191963,"USIN":"POS-2026-00007","DateTime":"2026-03-19T08:51:07","BuyerName":"Customer","BuyerPNTN":"","BuyerCNIC":"","BuyerPhoneNumber":"","TotalSaleValue":562.5,"TotalQuantity":1,"TotalTaxCharged":28.13,"Discount":0,"FurtherTax":0,"TotalBillAmount":590.63,"PaymentMode":2,"RefUSIN":null,"InvoiceType":1,"Items":[{"ItemCode":"0026","ItemName":"Broast Deal","Quantity":1,"PCTCode":"00000000","TaxRate":5,"SaleValue":562.5,"TotalAmount":590.63,"TaxCharged":28.13,"Discount":0,"FurtherTax":0,"InvoiceType":1,"RefUSIN":null}]}	{"InvoiceNumber":"191963FCMN583101308","Code":"100","Response":"Fiscal Invoice Number generated successfully.","Errors":null}	100	success	2026-03-19 09:58:03	2026-03-19 09:58:04
22	13	21	{"InvoiceNumber":"","POSID":191963,"USIN":"POS-2026-00008","DateTime":"2026-03-19T09:54:09","BuyerName":"jaafir","BuyerPNTN":"","BuyerCNIC":"","BuyerPhoneNumber":"","TotalSaleValue":450.5,"TotalQuantity":12,"TotalTaxCharged":22.53,"Discount":0,"FurtherTax":0,"TotalBillAmount":473.03,"PaymentMode":2,"RefUSIN":null,"InvoiceType":1,"Items":[{"ItemCode":"IT_0001","ItemName":"Daal","Quantity":1,"PCTCode":"00000000","TaxRate":5,"SaleValue":153,"TotalAmount":160.65,"TaxCharged":7.65,"Discount":0,"FurtherTax":0,"InvoiceType":1,"RefUSIN":null},{"ItemCode":"IT_0002","ItemName":"rice","Quantity":1,"PCTCode":"00000000","TaxRate":5,"SaleValue":297.5,"TotalAmount":312.38,"TaxCharged":14.88,"Discount":0,"FurtherTax":0,"InvoiceType":1,"RefUSIN":null}]}	{"InvoiceNumber":"Not Available","Code":"402","Response":"Fiscal invoice creation failed.","Errors":"Invoice validation failed: Total quantity mismatch: Invoice total 12.00 vs Sum of items 2.00"}	402	failed	2026-03-19 09:59:56	2026-03-19 09:59:57
23	13	21	{"InvoiceNumber":"","POSID":191963,"USIN":"POS-2026-00008","DateTime":"2026-03-19T09:54:09","BuyerName":"jaafir","BuyerPNTN":"","BuyerCNIC":"","BuyerPhoneNumber":"","TotalSaleValue":450.5,"TotalQuantity":2,"TotalTaxCharged":22.53,"Discount":0,"FurtherTax":0,"TotalBillAmount":473.03,"PaymentMode":2,"RefUSIN":null,"InvoiceType":1,"Items":[{"ItemCode":"IT_0001","ItemName":"Daal","Quantity":1,"PCTCode":"00000000","TaxRate":5,"SaleValue":153,"TotalAmount":160.65,"TaxCharged":7.65,"Discount":0,"FurtherTax":0,"InvoiceType":1,"RefUSIN":null},{"ItemCode":"IT_0002","ItemName":"rice","Quantity":1,"PCTCode":"00000000","TaxRate":5,"SaleValue":297.5,"TotalAmount":312.38,"TaxCharged":14.88,"Discount":0,"FurtherTax":0,"InvoiceType":1,"RefUSIN":null}]}	{"InvoiceNumber":"191963FCMO022408972","Code":"100","Response":"Fiscal Invoice Number generated successfully.","Errors":null}	100	success	2026-03-19 10:00:22	2026-03-19 10:00:23
24	13	22	{"InvoiceNumber":"","POSID":191963,"USIN":"POS-2026-00009","DateTime":"2026-03-19T10:18:18","BuyerName":"mian yonis","BuyerPNTN":"","BuyerCNIC":"","BuyerPhoneNumber":"","TotalSaleValue":750,"TotalQuantity":2,"TotalTaxCharged":120,"Discount":0,"FurtherTax":0,"TotalBillAmount":870,"PaymentMode":1,"RefUSIN":null,"InvoiceType":1,"Items":[{"ItemCode":"0026","ItemName":"Broast Deal","Quantity":1,"PCTCode":"00000000","TaxRate":16,"SaleValue":618.13,"TotalAmount":717.03,"TaxCharged":98.9,"Discount":0,"FurtherTax":0,"InvoiceType":1,"RefUSIN":null},{"ItemCode":"IT_0002","ItemName":"cold drink","Quantity":1,"PCTCode":"00000000","TaxRate":16,"SaleValue":131.87,"TotalAmount":152.97,"TaxCharged":21.1,"Discount":0,"FurtherTax":0,"InvoiceType":1,"RefUSIN":null}]}	{"InvoiceNumber":"191963FCMO1920146478","Code":"100","Response":"Fiscal Invoice Number generated successfully.","Errors":null}	100	success	2026-03-19 10:19:20	2026-03-19 10:19:20
40	13	34	{"InvoiceNumber":"","POSID":191963,"USIN":"POS-2026-00018","DateTime":"2026-03-24T16:54:23","BuyerName":"ammad","BuyerPNTN":"","BuyerCNIC":"","BuyerPhoneNumber":"","TotalSaleValue":500,"TotalQuantity":1,"TotalTaxCharged":80,"Discount":0,"FurtherTax":0,"TotalBillAmount":580,"PaymentMode":1,"RefUSIN":null,"InvoiceType":1,"Items":[{"ItemCode":"0025","ItemName":"Chicken Broast","Quantity":1,"PCTCode":"00000000","TaxRate":"16.00","SaleValue":500,"TotalAmount":580,"TaxCharged":80,"Discount":0,"FurtherTax":0,"InvoiceType":1,"RefUSIN":null}]}	{"InvoiceNumber":"191963FCRP5423712689","Code":"100","Response":"Fiscal Invoice Number generated successfully.","Errors":null}	100	success	2026-03-24 16:54:23	2026-03-24 16:54:23
25	13	23	{"InvoiceNumber":"","POSID":191963,"USIN":"POS-2026-00010","DateTime":"2026-03-19T10:27:29","BuyerName":"shoaib","BuyerPNTN":"","BuyerCNIC":"","BuyerPhoneNumber":"","TotalSaleValue":2502.4,"TotalQuantity":7,"TotalTaxCharged":400.38,"Discount":0,"FurtherTax":0,"TotalBillAmount":2902.78,"PaymentMode":1,"RefUSIN":null,"InvoiceType":1,"Items":[{"ItemCode":"0025","ItemName":"Chicken Broast","Quantity":1,"PCTCode":"00000000","TaxRate":16,"SaleValue":400,"TotalAmount":464,"TaxCharged":64,"Discount":0,"FurtherTax":0,"InvoiceType":1,"RefUSIN":null},{"ItemCode":"IT_0002","ItemName":"roti","Quantity":3,"PCTCode":"00000000","TaxRate":16,"SaleValue":12.8,"TotalAmount":44.54,"TaxCharged":6.14,"Discount":0,"FurtherTax":0,"InvoiceType":1,"RefUSIN":null},{"ItemCode":"IT_0003","ItemName":"mix sabzi","Quantity":1,"PCTCode":"00000000","TaxRate":16,"SaleValue":144,"TotalAmount":167.04,"TaxCharged":23.04,"Discount":0,"FurtherTax":0,"InvoiceType":1,"RefUSIN":null},{"ItemCode":"IT_0004","ItemName":"chicken karahi 1 kg","Quantity":1,"PCTCode":"00000000","TaxRate":16,"SaleValue":1280,"TotalAmount":1484.8,"TaxCharged":204.8,"Discount":0,"FurtherTax":0,"InvoiceType":1,"RefUSIN":null},{"ItemCode":"IT_0005","ItemName":"tikka boti plate 8pc","Quantity":1,"PCTCode":"00000000","TaxRate":16,"SaleValue":640,"TotalAmount":742.4,"TaxCharged":102.4,"Discount":0,"FurtherTax":0,"InvoiceType":1,"RefUSIN":null}]}	{"InvoiceNumber":"191963FCMO2838857174","Code":"100","Response":"Fiscal Invoice Number generated successfully.","Errors":null}	100	success	2026-03-19 10:28:38	2026-03-19 10:28:38
26	13	26	{"InvoiceNumber":"","POSID":191963,"USIN":"POS-2026-00011","DateTime":"2026-03-19T15:12:34","BuyerName":"Customer","BuyerPNTN":"","BuyerCNIC":"","BuyerPhoneNumber":"","TotalSaleValue":3000,"TotalQuantity":5,"TotalTaxCharged":480,"Discount":0,"FurtherTax":0,"TotalBillAmount":3480,"PaymentMode":1,"RefUSIN":null,"InvoiceType":1,"Items":[{"ItemCode":"0025","ItemName":"Chicken Broast","Quantity":3,"PCTCode":"00000000","TaxRate":"16.00","SaleValue":500,"TotalAmount":1740,"TaxCharged":240,"Discount":0,"FurtherTax":0,"InvoiceType":1,"RefUSIN":null},{"ItemCode":"0026","ItemName":"Broast Deal","Quantity":2,"PCTCode":"00000000","TaxRate":"16.00","SaleValue":750,"TotalAmount":1740,"TaxCharged":240,"Discount":0,"FurtherTax":0,"InvoiceType":1,"RefUSIN":null}]}	{"error":"cURL error 35: TLS connect error: error:0A000126:SSL routines::unexpected eof while reading (see https:\\/\\/curl.haxx.se\\/libcurl\\/c\\/libcurl-errors.html) for https:\\/\\/ims.pral.com.pk\\/ims\\/production\\/api\\/Live\\/PostData"}	500	failed	2026-03-19 15:13:07	2026-03-19 15:13:08
27	13	26	{"InvoiceNumber":"","POSID":191963,"USIN":"POS-2026-00011","DateTime":"2026-03-19T15:12:34","BuyerName":"Customer","BuyerPNTN":"","BuyerCNIC":"","BuyerPhoneNumber":"","TotalSaleValue":3000,"TotalQuantity":5,"TotalTaxCharged":480,"Discount":0,"FurtherTax":0,"TotalBillAmount":3480,"PaymentMode":1,"RefUSIN":null,"InvoiceType":1,"Items":[{"ItemCode":"0025","ItemName":"Chicken Broast","Quantity":3,"PCTCode":"00000000","TaxRate":"16.00","SaleValue":500,"TotalAmount":1740,"TaxCharged":240,"Discount":0,"FurtherTax":0,"InvoiceType":1,"RefUSIN":null},{"ItemCode":"0026","ItemName":"Broast Deal","Quantity":2,"PCTCode":"00000000","TaxRate":"16.00","SaleValue":750,"TotalAmount":1740,"TaxCharged":240,"Discount":0,"FurtherTax":0,"InvoiceType":1,"RefUSIN":null}]}	{"error":"cURL error 35: TLS connect error: error:0A000126:SSL routines::unexpected eof while reading (see https:\\/\\/curl.haxx.se\\/libcurl\\/c\\/libcurl-errors.html) for https:\\/\\/ims.pral.com.pk\\/ims\\/production\\/api\\/Live\\/PostData"}	500	failed	2026-03-24 06:42:25	2026-03-24 06:42:25
28	13	26	{"InvoiceNumber":"","POSID":191963,"USIN":"POS-2026-00011","DateTime":"2026-03-19T15:12:34","BuyerName":"Customer","BuyerPNTN":"","BuyerCNIC":"","BuyerPhoneNumber":"","TotalSaleValue":3000,"TotalQuantity":5,"TotalTaxCharged":480,"Discount":0,"FurtherTax":0,"TotalBillAmount":3480,"PaymentMode":1,"RefUSIN":null,"InvoiceType":1,"Items":[{"ItemCode":"0025","ItemName":"Chicken Broast","Quantity":3,"PCTCode":"00000000","TaxRate":"16.00","SaleValue":500,"TotalAmount":1740,"TaxCharged":240,"Discount":0,"FurtherTax":0,"InvoiceType":1,"RefUSIN":null},{"ItemCode":"0026","ItemName":"Broast Deal","Quantity":2,"PCTCode":"00000000","TaxRate":"16.00","SaleValue":750,"TotalAmount":1740,"TaxCharged":240,"Discount":0,"FurtherTax":0,"InvoiceType":1,"RefUSIN":null}]}	{"error":"cURL error 35: TLS connect error: error:0A000126:SSL routines::unexpected eof while reading (see https:\\/\\/curl.haxx.se\\/libcurl\\/c\\/libcurl-errors.html) for https:\\/\\/ims.pral.com.pk\\/ims\\/production\\/api\\/Live\\/PostData"}	500	failed	2026-03-24 06:42:33	2026-03-24 06:42:34
29	13	26	{"InvoiceNumber":"","POSID":191963,"USIN":"POS-2026-00011","DateTime":"2026-03-19T15:12:34","BuyerName":"Customer","BuyerPNTN":"","BuyerCNIC":"","BuyerPhoneNumber":"","TotalSaleValue":3000,"TotalQuantity":5,"TotalTaxCharged":480,"Discount":0,"FurtherTax":0,"TotalBillAmount":3480,"PaymentMode":1,"RefUSIN":null,"InvoiceType":1,"Items":[{"ItemCode":"0025","ItemName":"Chicken Broast","Quantity":3,"PCTCode":"00000000","TaxRate":"16.00","SaleValue":500,"TotalAmount":1740,"TaxCharged":240,"Discount":0,"FurtherTax":0,"InvoiceType":1,"RefUSIN":null},{"ItemCode":"0026","ItemName":"Broast Deal","Quantity":2,"PCTCode":"00000000","TaxRate":"16.00","SaleValue":750,"TotalAmount":1740,"TaxCharged":240,"Discount":0,"FurtherTax":0,"InvoiceType":1,"RefUSIN":null}]}	{"error":"cURL error 35: TLS connect error: error:0A000126:SSL routines::unexpected eof while reading (see https:\\/\\/curl.haxx.se\\/libcurl\\/c\\/libcurl-errors.html) for https:\\/\\/ims.pral.com.pk\\/ims\\/production\\/api\\/Live\\/PostData"}	500	failed	2026-03-24 06:45:09	2026-03-24 06:45:09
30	13	26	{"InvoiceNumber":"","POSID":191963,"USIN":"POS-2026-00011","DateTime":"2026-03-19T15:12:34","BuyerName":"Customer","BuyerPNTN":"","BuyerCNIC":"","BuyerPhoneNumber":"","TotalSaleValue":3000,"TotalQuantity":5,"TotalTaxCharged":480,"Discount":0,"FurtherTax":0,"TotalBillAmount":3480,"PaymentMode":1,"RefUSIN":null,"InvoiceType":1,"Items":[{"ItemCode":"0025","ItemName":"Chicken Broast","Quantity":3,"PCTCode":"00000000","TaxRate":"16.00","SaleValue":500,"TotalAmount":1740,"TaxCharged":240,"Discount":0,"FurtherTax":0,"InvoiceType":1,"RefUSIN":null},{"ItemCode":"0026","ItemName":"Broast Deal","Quantity":2,"PCTCode":"00000000","TaxRate":"16.00","SaleValue":750,"TotalAmount":1740,"TaxCharged":240,"Discount":0,"FurtherTax":0,"InvoiceType":1,"RefUSIN":null}]}	{"InvoiceNumber":"191963FCRL011234202","Code":"100","Response":"Fiscal Invoice Number generated successfully.","Errors":null}	100	success	2026-03-24 07:00:10	2026-03-24 07:00:11
31	13	27	{"InvoiceNumber":"","POSID":191963,"USIN":"POS-2026-00012","DateTime":"2026-03-24T07:54:51","BuyerName":"shoaib","BuyerPNTN":"","BuyerCNIC":"","BuyerPhoneNumber":"","TotalSaleValue":2301.6,"TotalQuantity":8,"TotalTaxCharged":109.48,"Discount":0,"FurtherTax":0,"TotalBillAmount":2411.08,"PaymentMode":2,"RefUSIN":null,"InvoiceType":1,"Items":[{"ItemCode":"0025","ItemName":"Chicken Broast","Quantity":1,"PCTCode":"00000000","TaxRate":"5.00","SaleValue":350,"TotalAmount":367.5,"TaxCharged":17.5,"Discount":0,"FurtherTax":0,"InvoiceType":1,"RefUSIN":null},{"ItemCode":"IT_0002","ItemName":"roti","Quantity":3,"PCTCode":"00000000","TaxRate":"5.00","SaleValue":11.2,"TotalAmount":35.28,"TaxCharged":1.68,"Discount":0,"FurtherTax":0,"InvoiceType":1,"RefUSIN":null},{"ItemCode":"IT_0003","ItemName":"mix sabzi","Quantity":1,"PCTCode":"00000000","TaxRate":"5.00","SaleValue":126,"TotalAmount":132.3,"TaxCharged":6.3,"Discount":0,"FurtherTax":0,"InvoiceType":1,"RefUSIN":null},{"ItemCode":"IT_0004","ItemName":"chicken karahi 1 kg","Quantity":1,"PCTCode":"00000000","TaxRate":"5.00","SaleValue":1120,"TotalAmount":1176,"TaxCharged":56,"Discount":0,"FurtherTax":0,"InvoiceType":1,"RefUSIN":null},{"ItemCode":"IT_0005","ItemName":"tikka boti plate 8pc","Quantity":1,"PCTCode":"00000000","TaxRate":"5.00","SaleValue":560,"TotalAmount":588,"TaxCharged":28,"Discount":0,"FurtherTax":0,"InvoiceType":1,"RefUSIN":null},{"ItemCode":"IT_0006","ItemName":"15 ltr drink","Quantity":1,"PCTCode":"00000000","TaxRate":0,"SaleValue":112,"TotalAmount":112,"TaxCharged":0,"Discount":0,"FurtherTax":0,"InvoiceType":1,"RefUSIN":null}]}	{"InvoiceNumber":"191963FCRL5557009892","Code":"100","Response":"Fiscal Invoice Number generated successfully.","Errors":null}	100	success	2026-03-24 07:55:56	2026-03-24 07:55:57
32	13	29	{"InvoiceNumber":"","POSID":0,"USIN":"POS-2026-00013","DateTime":"2026-03-24T09:08:36","BuyerName":"Customer","BuyerPNTN":"","BuyerCNIC":"","BuyerPhoneNumber":"","TotalSaleValue":425,"TotalQuantity":1,"TotalTaxCharged":68,"Discount":0,"FurtherTax":0,"TotalBillAmount":493,"PaymentMode":1,"RefUSIN":null,"InvoiceType":1,"Items":[{"ItemCode":"0025","ItemName":"Chicken Broast","Quantity":1,"PCTCode":"00000000","TaxRate":"16.00","SaleValue":425,"TotalAmount":493,"TaxCharged":68,"Discount":0,"FurtherTax":0,"InvoiceType":1,"RefUSIN":null}]}	{"InvoiceNumber":"Not Available","Code":"402","Response":"Fiscal invoice creation failed.","Errors":"Invoice validation failed: POS ID is required and must be greater than 0"}	402	failed	2026-03-24 09:21:09	2026-03-24 09:21:10
33	13	29	{"InvoiceNumber":"","POSID":0,"USIN":"POS-2026-00013","DateTime":"2026-03-24T09:08:36","BuyerName":"Customer","BuyerPNTN":"","BuyerCNIC":"","BuyerPhoneNumber":"","TotalSaleValue":425,"TotalQuantity":1,"TotalTaxCharged":68,"Discount":0,"FurtherTax":0,"TotalBillAmount":493,"PaymentMode":1,"RefUSIN":null,"InvoiceType":1,"Items":[{"ItemCode":"0025","ItemName":"Chicken Broast","Quantity":1,"PCTCode":"00000000","TaxRate":"16.00","SaleValue":425,"TotalAmount":493,"TaxCharged":68,"Discount":0,"FurtherTax":0,"InvoiceType":1,"RefUSIN":null}]}	{"InvoiceNumber":"Not Available","Code":"402","Response":"Fiscal invoice creation failed.","Errors":"Invoice validation failed: POS ID is required and must be greater than 0"}	402	failed	2026-03-24 09:21:17	2026-03-24 09:21:18
34	13	29	{"InvoiceNumber":"","POSID":0,"USIN":"POS-2026-00013","DateTime":"2026-03-24T09:08:36","BuyerName":"shoaib","BuyerPNTN":"","BuyerCNIC":"","BuyerPhoneNumber":"","TotalSaleValue":425,"TotalQuantity":1,"TotalTaxCharged":68,"Discount":0,"FurtherTax":0,"TotalBillAmount":493,"PaymentMode":1,"RefUSIN":null,"InvoiceType":1,"Items":[{"ItemCode":"0025","ItemName":"Chicken Broast","Quantity":1,"PCTCode":"00000000","TaxRate":"16.00","SaleValue":425,"TotalAmount":493,"TaxCharged":68,"Discount":0,"FurtherTax":0,"InvoiceType":1,"RefUSIN":null}]}	{"InvoiceNumber":"Not Available","Code":"402","Response":"Fiscal invoice creation failed.","Errors":"Invoice validation failed: POS ID is required and must be greater than 0"}	402	failed	2026-03-24 09:21:57	2026-03-24 09:21:58
35	13	29	{"InvoiceNumber":"","POSID":0,"USIN":"POS-2026-00013","DateTime":"2026-03-24T09:08:36","BuyerName":"shoaib","BuyerPNTN":"","BuyerCNIC":"","BuyerPhoneNumber":"","TotalSaleValue":425,"TotalQuantity":1,"TotalTaxCharged":68,"Discount":0,"FurtherTax":0,"TotalBillAmount":493,"PaymentMode":1,"RefUSIN":null,"InvoiceType":1,"Items":[{"ItemCode":"0025","ItemName":"Chicken Broast","Quantity":1,"PCTCode":"00000000","TaxRate":"16.00","SaleValue":425,"TotalAmount":493,"TaxCharged":68,"Discount":0,"FurtherTax":0,"InvoiceType":1,"RefUSIN":null}]}	{"InvoiceNumber":"Not Available","Code":"402","Response":"Fiscal invoice creation failed.","Errors":"Invoice validation failed: POS ID is required and must be greater than 0"}	402	failed	2026-03-24 09:22:03	2026-03-24 09:22:04
36	13	29	{"InvoiceNumber":"","POSID":191963,"USIN":"POS-2026-00013","DateTime":"2026-03-24T09:08:36","BuyerName":"shoaib","BuyerPNTN":"","BuyerCNIC":"","BuyerPhoneNumber":"","TotalSaleValue":425,"TotalQuantity":1,"TotalTaxCharged":68,"Discount":0,"FurtherTax":0,"TotalBillAmount":493,"PaymentMode":1,"RefUSIN":null,"InvoiceType":1,"Items":[{"ItemCode":"0025","ItemName":"Chicken Broast","Quantity":1,"PCTCode":"00000000","TaxRate":"16.00","SaleValue":425,"TotalAmount":493,"TaxCharged":68,"Discount":0,"FurtherTax":0,"InvoiceType":1,"RefUSIN":null}]}	{"InvoiceNumber":"191963FCRN2514608496","Code":"100","Response":"Fiscal Invoice Number generated successfully.","Errors":null}	100	success	2026-03-24 09:25:13	2026-03-24 09:25:14
\.


--
-- Data for Name: pricing_plans; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.pricing_plans (id, name, invoice_limit, price, created_at, updated_at, user_limit, branch_limit, is_trial, features, max_terminals, max_users, max_products, inventory_enabled, reports_enabled, price_monthly, product_type) FROM stdin;
4	Trial	20	0.00	2026-02-11 13:38:42	2026-02-11 13:38:42	2	1	t	["14-day free trial","20 invoices","2 users","1 branch","FBR Integration","PDF Generation"]	\N	\N	\N	t	t	\N	di
5	Retail	100	999.00	2026-02-11 13:38:42	2026-02-11 13:38:42	2	1	f	["100 invoices/month","2 users","1 branch","FBR Integration","PDF Generation","Compliance Scoring"]	\N	\N	\N	t	t	\N	di
7	Industrial	2500	6999.00	2026-02-11 13:38:42	2026-02-11 13:38:42	15	-1	f	["2,500 invoices/month","15 users","Unlimited branches","FBR Integration","PDF Generation","Compliance Scoring","MIS Reports","Customer Ledger","Priority Support"]	\N	\N	\N	t	t	\N	di
6	Business	700	2999.00	2026-02-11 13:38:42	2026-02-11 13:38:42	5	3	f	["700 invoices/month","5 users","3 branches","FBR Integration","PDF Generation","Compliance Scoring","MIS Reports","Customer Ledger"]	5	10	500	t	t	2999.00	di
8	Enterprise	-1	15000.00	2026-02-11 13:38:42	2026-02-11 13:38:42	-1	-1	f	["Unlimited invoices","Unlimited users","Unlimited branches","FBR Integration","PDF Generation","Compliance Scoring","MIS Reports","Customer Ledger","Priority Support","Dedicated Account Manager","Custom Integrations"]	\N	\N	\N	t	t	15000.00	di
9	Starter	500	9999.00	2026-03-07 17:51:30	2026-03-07 17:51:30	1	1	f	["POS Billing","Thermal Receipt","Cash \\/ Card \\/ QR Payments","Basic Reports"]	1	2	100	f	t	\N	pos
10	Business	2000	14999.00	2026-03-07 17:51:30	2026-03-07 17:51:30	5	3	f	["POS Billing","Offline Billing","PRA Integration","Advanced Reports","Multi-terminal Support"]	3	5	500	f	t	\N	pos
11	Pro	-1	24999.00	2026-03-07 17:51:30	2026-03-07 17:51:30	-1	-1	f	["PRA Fiscal Reporting","Inventory Module","Advanced Analytics","Priority Support"]	-1	-1	-1	t	t	\N	pos
\.


--
-- Data for Name: products; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.products (id, company_id, name, hs_code, pct_code, default_tax_rate, uom, schedule_type, sro_reference, default_price, is_active, created_at, updated_at, serial_number, mrp) FROM stdin;
1	2	Cooking Oil 1L	15179090	1517.9090	18.00	Litre	Standard	\N	450.00	t	2026-02-11 11:46:17	2026-02-11 11:46:17	\N	\N
2	2	Cement Bag	25232900	2523.2900	18.00	Bag	Standard	\N	1250.00	t	2026-02-11 11:46:17	2026-02-11 11:46:17	\N	\N
3	2	Fertilizer	31021000	3102.1000	0.00	Kg	Exempt	SRO 1125(I)/2011	3800.00	t	2026-02-11 11:46:17	2026-02-11 11:46:17	\N	\N
4	7	Dap	3105.3000	\N	5.00	Kilograms	3rd_schedule	3rd Schedule goods	260.21	t	2026-02-14 09:54:08	2026-02-14 12:28:09	51	260.21
7	11	TEST POS ITEM 2	00000000		0.00	pcs	standard		100.00	t	2026-03-24 11:51:53	2026-03-24 11:51:53	\N	120.00
\.


--
-- Data for Name: province_tax_rules; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.province_tax_rules (id, province, hs_code, override_tax_rate, override_schedule_type, override_sro_required, override_mrp_required, description, is_active, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: purchase_order_items; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.purchase_order_items (id, purchase_order_id, product_id, quantity, unit_price, total_price, received_quantity, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: purchase_orders; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.purchase_orders (id, company_id, supplier_id, branch_id, po_number, status, order_date, expected_date, received_date, total_amount, notes, created_by, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: sector_tax_rules; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.sector_tax_rules (id, sector_type, hs_code, override_tax_rate, override_schedule_type, override_sro_required, override_mrp_required, description, is_active, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: security_logs; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.security_logs (id, user_id, action, ip_address, user_agent, metadata, created_at) FROM stdin;
1	2	login	127.0.0.1	curl/8.14.1	\N	2026-02-11 10:23:03
2	1	login	127.0.0.1	curl/8.14.1	\N	2026-02-11 10:23:10
3	2	login	127.0.0.1	curl/8.14.1	\N	2026-02-11 10:43:34
4	1	login	127.0.0.1	curl/8.14.1	\N	2026-02-11 10:43:43
5	1	login	10.83.4.172	Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 Replit-Bonsai/2.170.0 (iOS 26.2.1)	\N	2026-02-11 10:46:28
6	2	login	127.0.0.1	curl/8.14.1	\N	2026-02-11 11:04:12
7	1	login	127.0.0.1	curl/8.14.1	\N	2026-02-11 11:04:20
8	2	login	127.0.0.1	curl/8.14.1	\N	2026-02-11 11:07:47
9	2	login	127.0.0.1	curl/8.14.1	\N	2026-02-11 11:08:50
10	\N	failed_login	10.83.0.98	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36	{"email":"admin@test.com"}	2026-02-11 11:31:54
11	1	login	10.83.11.158	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36	\N	2026-02-11 11:32:08
12	2	login	127.0.0.1	curl/8.14.1	\N	2026-02-11 11:36:07
13	1	login	127.0.0.1	curl/8.14.1	\N	2026-02-11 11:36:20
14	4	login	127.0.0.1	curl/8.14.1	\N	2026-02-11 11:47:27
15	4	login	10.83.1.63	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36	\N	2026-02-11 11:51:06
16	1	login	10.83.9.178	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36	\N	2026-02-11 11:57:48
17	1	login	127.0.0.1	curl/8.14.1	\N	2026-02-11 12:02:44
18	4	login	127.0.0.1	curl/8.14.1	\N	2026-02-11 12:02:54
19	4	login	10.83.8.193	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36	\N	2026-02-11 12:06:34
20	4	login	127.0.0.1	curl/8.14.1	\N	2026-02-11 12:23:53
21	2	login	127.0.0.1	curl/8.14.1	\N	2026-02-11 12:32:24
22	1	login	127.0.0.1	curl/8.14.1	\N	2026-02-11 12:32:33
23	5	self_registration	127.0.0.1	curl/8.14.1	{"company_id":3,"company_name":"Test Corp Pvt Ltd"}	2026-02-11 12:32:48
24	5	login	127.0.0.1	curl/8.14.1	\N	2026-02-11 12:32:48
25	2	company_user_created	127.0.0.1	curl/8.14.1	{"new_user_id":6,"role":"employee","company_id":1}	2026-02-11 12:33:02
26	2	fbr_settings_updated	127.0.0.1	curl/8.14.1	{"company_id":1,"environment":"sandbox"}	2026-02-11 12:33:03
27	1	company_suspended	127.0.0.1	curl/8.14.1	{"company_id":1}	2026-02-11 12:33:17
28	3	login	127.0.0.1	curl/8.14.1	\N	2026-02-11 12:33:18
29	1	company_unsuspended	127.0.0.1	curl/8.14.1	{"company_id":1}	2026-02-11 12:33:19
30	3	login	127.0.0.1	curl/8.14.1	\N	2026-02-11 12:33:44
31	1	login	127.0.0.1	curl/8.14.1	\N	2026-02-11 13:08:19
32	2	login	127.0.0.1	curl/8.14.1	\N	2026-02-11 13:08:30
33	3	login	127.0.0.1	curl/8.14.1	\N	2026-02-11 13:10:23
34	7	self_registration	127.0.0.1	curl/8.14.1	{"company_id":4,"company_name":"New Test Corp"}	2026-02-11 13:10:35
35	1	company_approved	127.0.0.1	curl/8.14.1	{"company_id":4,"name":"New Test Corp"}	2026-02-11 13:10:53
36	1	login	127.0.0.1	curl/8.14.1	\N	2026-02-11 13:45:30
37	2	login	127.0.0.1	curl/8.14.1	\N	2026-02-11 13:45:30
38	2	login	127.0.0.1	curl/8.14.1	\N	2026-02-11 13:48:07
39	2	login	127.0.0.1	curl/8.14.1	\N	2026-02-11 14:17:07
40	1	login	127.0.0.1	curl/8.14.1	\N	2026-02-11 14:17:09
41	2	login	127.0.0.1	curl/8.14.1	\N	2026-02-11 14:17:25
42	2	login	127.0.0.1	curl/8.14.1	\N	2026-02-11 14:23:16
43	2	login	127.0.0.1	curl/8.14.1	\N	2026-02-11 14:38:02
44	2	login	127.0.0.1	curl/8.14.1	\N	2026-02-11 14:38:17
45	3	login	10.83.8.193	Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.2 Mobile/15E148 Safari/604.1	\N	2026-02-11 15:22:08
46	\N	failed_login	10.83.8.193	Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.2 Mobile/15E148 Safari/604.1	{"email":"demo@taxnest.com"}	2026-02-11 15:27:09
47	\N	failed_login	10.83.4.183	Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.2 Mobile/15E148 Safari/604.1	{"email":"demo@taxnest.com"}	2026-02-11 15:27:20
48	\N	failed_login	10.83.4.172	Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.2 Mobile/15E148 Safari/604.1	{"email":"demo@taxnest.pk"}	2026-02-11 15:27:37
49	\N	failed_login	10.83.11.158	Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.2 Mobile/15E148 Safari/604.1	{"email":"demo@taxnest.pk"}	2026-02-11 15:27:45
50	\N	failed_login	10.83.4.172	Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.2 Mobile/15E148 Safari/604.1	{"email":"demo@taxnest.pk"}	2026-02-11 15:27:50
51	1	login	127.0.0.1	curl/8.14.1	\N	2026-02-11 15:30:19
52	1	login	127.0.0.1	curl/8.14.1	\N	2026-02-11 15:31:41
53	1	login	127.0.0.1	curl/8.14.1	\N	2026-02-11 15:32:24
54	2	login	127.0.0.1	curl/8.14.1	\N	2026-02-11 15:40:04
55	4	login	10.83.4.183	Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.2 Mobile/15E148 Safari/604.1	\N	2026-02-11 15:43:24
56	2	login	127.0.0.1	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/140.0.0.0 Safari/537.36	\N	2026-02-11 16:03:25
57	4	login	127.0.0.1	Symfony	\N	2026-02-11 17:41:22
58	4	login	127.0.0.1	Symfony	\N	2026-02-11 17:41:43
59	4	login	10.83.7.155	Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.2 Mobile/15E148 Safari/604.1	\N	2026-02-11 18:17:47
60	4	login	10.83.11.158	Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.2 Mobile/15E148 Safari/604.1	\N	2026-02-12 02:15:16
61	4	login	10.83.11.158	Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.2 Mobile/15E148 Safari/604.1	\N	2026-02-12 02:15:17
62	4	login	10.83.12.104	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36	\N	2026-02-12 05:55:26
63	4	login	10.83.12.104	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36	\N	2026-02-12 06:32:59
64	4	login	10.83.6.125	Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 Replit-Bonsai/2.170.0 (iOS 26.2.1)	\N	2026-02-12 09:39:27
65	4	login	10.83.6.125	Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 Replit-Bonsai/2.170.0 (iOS 26.2.1)	\N	2026-02-12 09:39:29
66	4	login	10.83.3.120	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36	\N	2026-02-12 10:00:22
67	4	login	10.83.4.172	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36	\N	2026-02-12 10:01:09
68	2	login	127.0.0.1	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/140.0.0.0 Safari/537.36	\N	2026-02-12 10:45:38
69	2	login	127.0.0.1	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/140.0.0.0 Safari/537.36	\N	2026-02-12 10:56:52
70	1	login	127.0.0.1	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/140.0.0.0 Safari/537.36	\N	2026-02-12 10:58:35
71	2	login	127.0.0.1	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/140.0.0.0 Safari/537.36	\N	2026-02-12 11:00:41
72	2	login	127.0.0.1	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/140.0.0.0 Safari/537.36	\N	2026-02-12 11:03:25
73	1	login	127.0.0.1	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/140.0.0.0 Safari/537.36	\N	2026-02-12 11:03:48
74	2	login	127.0.0.1	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/140.0.0.0 Safari/537.36	\N	2026-02-12 11:15:56
75	2	login	127.0.0.1	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/140.0.0.0 Safari/537.36	\N	2026-02-12 11:38:56
76	2	login	127.0.0.1	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/140.0.0.0 Safari/537.36	\N	2026-02-12 11:39:20
77	2	login	127.0.0.1	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/140.0.0.0 Safari/537.36	\N	2026-02-12 11:45:53
78	2	login	127.0.0.1	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/140.0.0.0 Safari/537.36	\N	2026-02-12 11:49:10
79	2	login	127.0.0.1	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/140.0.0.0 Safari/537.36	\N	2026-02-12 11:53:04
80	4	login	127.0.0.1	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/140.0.0.0 Safari/537.36	\N	2026-02-12 11:53:19
81	4	login	10.83.0.98	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36	\N	2026-02-12 11:54:21
82	4	login	127.0.0.1	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/140.0.0.0 Safari/537.36	\N	2026-02-12 12:12:38
83	4	login	127.0.0.1	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/140.0.0.0 Safari/537.36	\N	2026-02-12 12:13:34
84	1	login	127.0.0.1	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/140.0.0.0 Safari/537.36	\N	2026-02-12 12:22:35
85	4	login	127.0.0.1	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/140.0.0.0 Safari/537.36	\N	2026-02-12 12:24:01
86	4	login	10.83.10.25	Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.2 Mobile/15E148 Safari/604.1	\N	2026-02-12 14:59:10
87	2	login	127.0.0.1	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/140.0.0.0 Safari/537.36	\N	2026-02-12 15:07:30
88	2	login	127.0.0.1	curl/8.14.1	\N	2026-02-12 15:08:44
89	2	login	127.0.0.1	curl/8.14.1	\N	2026-02-12 15:16:39
90	2	login	127.0.0.1	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/140.0.0.0 Safari/537.36	\N	2026-02-12 15:17:04
91	2	login	127.0.0.1	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/140.0.0.0 Safari/537.36	\N	2026-02-12 15:26:19
92	2	login	127.0.0.1	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/140.0.0.0 Safari/537.36	\N	2026-02-12 15:28:40
93	2	login	127.0.0.1	curl/8.14.1	\N	2026-02-12 15:28:50
94	2	login	127.0.0.1	curl/8.14.1	\N	2026-02-12 15:43:12
95	2	login	127.0.0.1	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/140.0.0.0 Safari/537.36	\N	2026-02-12 15:43:40
96	2	login	127.0.0.1	curl/8.14.1	\N	2026-02-12 15:45:27
97	4	login	10.83.3.120	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36	\N	2026-02-13 04:56:52
98	1	login	127.0.0.1	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/140.0.0.0 Safari/537.36	\N	2026-02-13 05:02:26
99	1	login	127.0.0.1	curl/8.14.1	\N	2026-02-13 05:03:23
100	1	company_created	127.0.0.1	curl/8.14.1	{"company_id":5,"name":"Sandbox Test Company"}	2026-02-13 05:03:24
101	1	login	127.0.0.1	curl/8.14.1	\N	2026-02-13 05:03:34
102	1	login	127.0.0.1	curl/8.14.1	\N	2026-02-13 05:03:51
103	1	company_created	127.0.0.1	curl/8.14.1	{"company_id":6,"name":"FBR Testing Corp"}	2026-02-13 05:03:52
104	1	login	127.0.0.1	curl/8.14.1	\N	2026-02-13 05:12:05
105	1	company_created	127.0.0.1	curl/8.14.1	{"company_id":7,"name":"ZIA CORPORATION"}	2026-02-13 05:12:05
106	1	login	127.0.0.1	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/140.0.0.0 Safari/537.36	\N	2026-02-13 05:12:26
107	10	login	127.0.0.1	curl/8.14.1	\N	2026-02-13 05:12:40
108	10	login	127.0.0.1	curl/8.14.1	\N	2026-02-13 05:13:15
109	10	login	127.0.0.1	curl/8.14.1	\N	2026-02-13 05:14:10
110	10	fbr_settings_updated	127.0.0.1	curl/8.14.1	{"company_id":7,"environment":"sandbox"}	2026-02-13 05:14:41
111	1	login	127.0.0.1	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/140.0.0.0 Safari/537.36	\N	2026-02-13 05:15:51
112	\N	failed_login	127.0.0.1	curl/8.14.1	{"email":"8612580zur@gmail.com"}	2026-02-13 05:20:26
113	10	login	10.83.11.158	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36	\N	2026-02-13 05:28:01
114	10	fbr_settings_updated	10.83.11.158	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36	{"company_id":7,"environment":"sandbox"}	2026-02-13 05:29:20
115	1	login	127.0.0.1	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/140.0.0.0 Safari/537.36	\N	2026-02-13 05:32:12
116	10	login	10.83.12.104	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36	\N	2026-02-13 05:32:18
117	1	login	127.0.0.1	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/140.0.0.0 Safari/537.36	\N	2026-02-13 05:36:34
118	1	login	127.0.0.1	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/140.0.0.0 Safari/537.36	\N	2026-02-13 05:45:08
119	10	login	10.83.0.98	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36	\N	2026-02-13 07:04:35
120	10	login	10.83.2.144	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36	\N	2026-02-13 09:21:56
121	10	login	10.83.0.98	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36	\N	2026-02-13 10:44:45
122	10	login	10.83.9.201	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/144.0.0.0 Safari/537.36	\N	2026-02-13 11:28:47
123	\N	failed_login	127.0.0.1	curl/8.14.1	{"email":"8612580zur@gmail.com"}	2026-02-13 11:58:34
124	\N	failed_login	127.0.0.1	curl/8.14.1	{"email":"8612580zur@gmail.com"}	2026-02-13 11:58:51
125	10	login	127.0.0.1	curl/8.14.1	\N	2026-02-13 11:59:21
126	10	login	127.0.0.1	curl/8.14.1	\N	2026-02-13 12:05:57
127	\N	failed_login	10.83.12.104	Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 Replit-Bonsai/2.170.0 (iOS 26.2.1)	{"email":"8612580zur@gmail.com"}	2026-02-13 12:25:54
128	\N	failed_login	10.83.9.201	Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 Replit-Bonsai/2.170.0 (iOS 26.2.1)	{"email":"8612580zur@gmail.com"}	2026-02-13 12:26:06
129	\N	failed_login	10.83.11.158	Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 Replit-Bonsai/2.170.0 (iOS 26.2.1)	{"email":"8612580zur@gmail.com"}	2026-02-13 12:28:40
130	10	login	10.83.4.236	Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 Replit-Bonsai/2.170.0 (iOS 26.2.1)	\N	2026-02-13 12:28:54
131	\N	failed_login	10.83.12.32	Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 Replit-Bonsai/2.170.0 (iOS 26.2.1)	{"email":"8612580zur@gmail.com"}	2026-02-13 17:34:42
132	10	login	10.83.12.32	Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 Replit-Bonsai/2.170.0 (iOS 26.2.1)	\N	2026-02-13 17:34:54
133	\N	failed_login	10.83.11.49	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36	{"email":"8612580zur@gmail.com"}	2026-02-14 06:00:40
134	10	login	10.83.11.49	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36	\N	2026-02-14 06:00:50
135	10	login	10.83.13.14	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36	\N	2026-02-14 06:01:39
136	10	company_profile_updated	10.83.7.231	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36	{"company_id":7}	2026-02-14 07:09:31
137	10	fbr_connection_test	10.83.9.201	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36	{"company_id":7,"environment":"production","result":"healthy"}	2026-02-14 07:40:11
138	10	fbr_connection_test	10.83.9.201	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36	{"company_id":7,"environment":"production","result":"healthy"}	2026-02-14 07:40:15
139	10	fbr_settings_updated	10.83.11.49	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36	{"company_id":7,"environment":"production"}	2026-02-14 08:12:27
140	10	fbr_connection_test	10.83.4.235	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36	{"company_id":7,"environment":"production","result":"healthy"}	2026-02-14 08:31:00
141	10	fbr_settings_updated	10.83.4.235	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36	{"company_id":7,"environment":"production"}	2026-02-14 08:31:04
142	10	fbr_connection_test	10.83.4.235	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36	{"company_id":7,"environment":"production","result":"healthy"}	2026-02-14 08:31:04
143	10	fbr_connection_test	10.83.7.231	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36	{"company_id":7,"environment":"production","result":"healthy"}	2026-02-14 08:37:03
144	10	fbr_settings_updated	10.83.4.235	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36	{"company_id":7,"environment":"production"}	2026-02-14 08:37:05
145	10	fbr_connection_test	10.83.4.235	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36	{"company_id":7,"environment":"production","result":"healthy"}	2026-02-14 08:37:05
146	10	fbr_connection_test	10.83.4.235	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36	{"company_id":7,"environment":"production","result":"healthy"}	2026-02-14 08:37:28
147	10	fbr_connection_test	10.83.4.235	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36	{"company_id":7,"environment":"production","result":"healthy"}	2026-02-14 08:40:01
148	10	fbr_connection_test	10.83.4.235	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36	{"company_id":7,"environment":"production","result":"healthy"}	2026-02-14 08:40:04
149	10	fbr_settings_updated	10.83.4.235	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36	{"company_id":7,"environment":"production"}	2026-02-14 08:40:06
150	10	fbr_connection_test	10.83.4.235	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36	{"company_id":7,"environment":"production","result":"healthy"}	2026-02-14 08:40:06
151	10	fbr_connection_test	10.83.7.231	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36	{"company_id":7,"environment":"production","result":"healthy"}	2026-02-14 08:43:08
152	10	fbr_connection_test	10.83.7.231	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36	{"company_id":7,"environment":"production","result":"healthy"}	2026-02-14 08:43:34
153	10	fbr_settings_updated	10.83.7.231	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36	{"company_id":7,"environment":"production"}	2026-02-14 08:43:39
154	10	fbr_connection_test	10.83.7.231	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36	{"company_id":7,"environment":"production","result":"healthy"}	2026-02-14 08:43:39
155	10	fbr_settings_updated	10.83.7.231	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36	{"company_id":7,"environment":"production"}	2026-02-14 08:46:30
156	10	fbr_connection_test	10.83.7.231	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36	{"company_id":7,"environment":"production","result":"healthy"}	2026-02-14 08:46:30
157	10	fbr_settings_updated	10.83.4.235	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36	{"company_id":7,"environment":"production"}	2026-02-14 08:47:18
158	10	fbr_connection_test	10.83.4.235	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36	{"company_id":7,"environment":"production","result":"healthy"}	2026-02-14 08:47:19
159	10	fbr_settings_updated	10.83.7.231	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36	{"company_id":7,"environment":"production"}	2026-02-14 08:48:42
160	10	fbr_connection_test	10.83.7.231	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36	{"company_id":7,"environment":"production","result":"healthy"}	2026-02-14 08:48:43
161	10	fbr_settings_updated	10.83.4.235	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36	{"company_id":7,"environment":"production"}	2026-02-14 08:51:01
162	10	fbr_connection_test	10.83.4.235	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36	{"company_id":7,"environment":"production","result":"healthy"}	2026-02-14 08:51:01
163	10	fbr_settings_updated	10.83.4.235	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36	{"company_id":7,"environment":"production"}	2026-02-14 08:53:54
164	10	fbr_connection_test	10.83.4.235	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36	{"company_id":7,"environment":"production","result":"healthy"}	2026-02-14 08:53:54
165	10	fbr_connection_test	10.83.4.235	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36	{"company_id":7,"environment":"production","result":"healthy"}	2026-02-14 08:53:59
166	10	fbr_settings_updated	10.83.3.32	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36	{"company_id":7,"environment":"production"}	2026-02-14 09:08:15
167	10	fbr_connection_test	10.83.3.32	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36	{"company_id":7,"environment":"production","result":"healthy"}	2026-02-14 09:08:16
168	10	login	10.83.9.201	Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 Replit-Bonsai/2.170.0 (iOS 26.3)	\N	2026-02-14 09:37:52
169	10	login	10.83.9.201	Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 Replit-Bonsai/2.170.0 (iOS 26.3)	\N	2026-02-14 09:39:00
170	10	login	10.83.7.231	Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 Replit-Bonsai/2.170.0 (iOS 26.3)	\N	2026-02-14 09:39:11
171	10	fbr_connection_test	10.83.9.201	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36	{"company_id":7,"environment":"production","result":"healthy"}	2026-02-14 10:18:43
172	10	fbr_settings_updated	10.83.9.201	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36	{"company_id":7,"environment":"production"}	2026-02-14 10:18:46
173	10	fbr_connection_test	10.83.9.201	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36	{"company_id":7,"environment":"production","result":"healthy"}	2026-02-14 10:18:47
174	10	login	10.83.8.57	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36	\N	2026-02-14 12:29:16
175	10	login	10.83.13.14	Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 Replit-Bonsai/2.170.0 (iOS 26.3)	\N	2026-02-14 12:31:36
176	10	login	10.83.1.3	Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 Replit-Bonsai/2.170.0 (iOS 26.3)	\N	2026-02-14 15:32:08
177	10	login	10.83.13.14	Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 Replit-Bonsai/2.170.0 (iOS 26.3)	\N	2026-02-14 19:37:11
178	10	login	10.83.13.14	Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 Replit-Bonsai/2.170.0 (iOS 26.3)	\N	2026-02-15 13:21:46
179	10	login	10.83.4.6	Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 Replit-Bonsai/2.170.0 (iOS 26.3)	\N	2026-02-15 13:22:40
180	10	login	10.83.7.79	Mozilla/5.0 (iPhone; CPU iPhone OS 26_3_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/145.0.7632.55 Mobile/15E148 Safari/604.1	\N	2026-02-15 14:22:12
181	10	login	10.83.7.79	Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 Replit-Bonsai/2.170.0 (iOS 26.3)	\N	2026-02-15 14:44:01
182	10	login	10.83.6.62	Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 Replit-Bonsai/2.170.0 (iOS 26.3)	\N	2026-02-15 14:44:31
183	10	login	10.83.6.62	Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 Replit-Bonsai/2.170.0 (iOS 26.3)	\N	2026-02-15 14:44:57
184	10	login	10.83.9.28	Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 Replit-Bonsai/2.170.0 (iOS 26.3)	\N	2026-02-15 17:44:05
185	10	login	10.83.3.32	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36	\N	2026-02-16 06:01:22
186	10	login	10.83.4.6	Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 Replit-Bonsai/2.170.0 (iOS 26.3)	\N	2026-02-16 06:17:55
187	10	login	10.83.11.49	Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 Replit-Bonsai/2.170.0 (iOS 26.3)	\N	2026-02-16 08:20:01
188	10	login	10.83.11.49	Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 Replit-Bonsai/2.170.0 (iOS 26.3)	\N	2026-02-16 08:21:03
189	10	login	10.83.4.6	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36	\N	2026-02-16 09:02:43
190	10	login	10.83.12.54	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36	\N	2026-02-16 09:03:19
191	10	login	10.83.4.6	Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 Replit-Bonsai/2.170.0 (iOS 26.3)	\N	2026-02-16 12:21:31
192	10	login	10.83.12.54	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36	\N	2026-02-16 12:32:27
193	10	login	10.83.11.49	Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 Replit-Bonsai/2.170.0 (iOS 26.3)	\N	2026-02-16 15:18:25
194	10	login	10.83.12.54	Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 Replit-Bonsai/2.170.0 (iOS 26.3)	\N	2026-02-17 05:23:32
195	\N	failed_login	127.0.0.1	curl/8.14.1	{"email":"8612580zur@gmail.com"}	2026-02-17 05:27:59
196	10	login	10.83.3.32	Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 Replit-Bonsai/2.170.0 (iOS 26.3)	\N	2026-02-17 05:29:34
197	10	login	10.83.3.32	Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 Replit-Bonsai/2.170.0 (iOS 26.3)	\N	2026-02-17 05:45:04
198	10	login	10.83.11.49	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36	\N	2026-02-17 05:50:29
199	10	login	10.83.12.54	Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 Replit-Bonsai/2.170.0 (iOS 26.3)	\N	2026-02-17 09:38:20
200	10	login	10.83.8.57	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36	\N	2026-02-17 10:15:30
201	10	login	10.83.4.6	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36	\N	2026-02-17 12:37:37
202	10	login	10.83.7.125	Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 Replit-Bonsai/2.170.0 (iOS 26.3)	\N	2026-02-17 13:59:21
203	1	login	10.83.4.76	Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 Replit-Bonsai/2.170.0 (iOS 26.3)	\N	2026-02-17 16:31:29
204	1	login	10.83.11.49	Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 Replit-Bonsai/2.170.0 (iOS 26.3)	\N	2026-02-17 16:32:12
205	1	login	10.83.7.125	Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 Replit-Bonsai/2.170.0 (iOS 26.3)	\N	2026-02-17 16:32:22
206	1	login	10.83.9.30	Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 Replit-Bonsai/2.170.0 (iOS 26.3)	\N	2026-02-17 19:31:27
207	1	login	10.83.9.77	Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 Replit-Bonsai/2.170.0 (iOS 26.3)	\N	2026-02-18 06:14:24
208	10	login	10.83.5.98	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36	\N	2026-02-18 07:23:47
209	1	login	10.83.1.116	Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 Replit-Bonsai/2.170.0 (iOS 26.3)	\N	2026-02-18 17:29:29
210	1	login	10.83.14.40	Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 Replit-Bonsai/2.170.0 (iOS 26.3)	\N	2026-02-21 17:46:42
211	1	login	10.83.14.40	Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 Replit-Bonsai/2.170.0 (iOS 26.3)	\N	2026-02-21 17:46:58
212	1	company_plan_changed	10.83.14.40	Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 Replit-Bonsai/2.170.0 (iOS 26.3)	{"company_id":7,"new_plan_id":"8"}	2026-02-21 17:47:28
213	1	login	10.83.10.108	Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 Replit-Bonsai/2.170.0 (iOS 26.3)	\N	2026-02-22 17:27:04
214	1	login	10.83.6.171	Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 Replit-Bonsai/2.170.0 (iOS 26.3)	\N	2026-02-22 17:30:35
215	1	login	10.83.9.233	Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 Replit-Bonsai/2.170.0 (iOS 26.3)	\N	2026-02-22 17:30:36
216	1	login	10.83.7.56	Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 Replit-Bonsai/2.170.0 (iOS 26.3)	\N	2026-03-05 16:44:09
217	\N	failed_login	10.83.9.154	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36	{"email":"fbrtestadmin@testing.com"}	2026-03-05 18:16:33
218	5	login	10.83.9.154	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36	\N	2026-03-05 18:20:09
219	5	login	10.83.4.3	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36	\N	2026-03-05 18:32:47
220	5	login	10.83.3.246	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36	\N	2026-03-05 18:43:04
221	5	login	10.83.10.64	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36	\N	2026-03-05 18:44:19
222	5	login	10.83.7.56	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36	\N	2026-03-05 19:05:32
223	5	login	10.83.11.16	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36	\N	2026-03-05 23:54:38
224	5	login	10.83.2.115	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36	\N	2026-03-06 00:16:16
225	5	login	10.83.10.64	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36	\N	2026-03-06 00:26:10
226	5	login	10.83.6.27	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36	\N	2026-03-06 00:28:47
227	9	login	10.83.9.154	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36	\N	2026-03-06 02:40:17
228	9	login	10.83.13.168	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36	\N	2026-03-06 02:42:12
229	1	login	127.0.0.1	curl/8.14.1	\N	2026-03-06 04:49:34
230	1	login	127.0.0.1	curl/8.14.1	\N	2026-03-06 04:49:42
231	1	login	127.0.0.1	curl/8.14.1	\N	2026-03-06 04:51:06
232	1	login	10.83.3.246	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36	\N	2026-03-06 04:52:10
233	1	login	127.0.0.1	curl/8.14.1	\N	2026-03-06 04:56:07
234	11	login	127.0.0.1	curl/8.14.1	\N	2026-03-06 08:25:41
235	11	login	10.83.3.246	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36	\N	2026-03-06 08:34:00
236	11	login	127.0.0.1	curl/8.14.1	\N	2026-03-06 08:46:11
237	11	login	127.0.0.1	curl/8.14.1	\N	2026-03-06 08:47:39
238	11	login	10.83.5.49	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36	\N	2026-03-06 08:49:05
239	11	login	10.83.9.154	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36	\N	2026-03-06 09:09:21
240	11	login	10.83.9.154	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36	\N	2026-03-06 10:13:08
241	11	login	10.83.5.49	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36	\N	2026-03-06 10:20:50
242	1	login	127.0.0.1	curl/8.14.1	\N	2026-03-06 10:38:27
243	11	login	127.0.0.1	curl/8.14.1	\N	2026-03-06 10:48:25
244	11	login	10.83.7.56	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36	\N	2026-03-06 10:49:30
245	11	login	10.83.8.132	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36	\N	2026-03-06 10:49:35
246	1	login	127.0.0.1	curl/8.14.1	\N	2026-03-06 10:52:30
247	1	login	10.83.1.154	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36	\N	2026-03-06 10:53:31
248	12	login	10.83.8.132	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36	\N	2026-03-06 10:55:04
249	11	login	10.83.8.132	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36	\N	2026-03-06 10:56:24
250	12	login	127.0.0.1	curl/8.14.1	\N	2026-03-06 10:57:44
251	12	login	127.0.0.1	curl/8.14.1	\N	2026-03-06 10:58:10
252	12	login	10.83.10.64	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36	\N	2026-03-06 10:59:14
253	1	login	10.83.10.64	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36	\N	2026-03-06 10:59:57
254	11	login	10.83.7.56	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36	\N	2026-03-06 11:01:24
255	11	login	127.0.0.1	curl/8.14.1	\N	2026-03-06 11:06:05
256	11	login	10.83.3.246	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36	\N	2026-03-06 11:07:11
257	11	login	10.83.5.49	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36	\N	2026-03-06 11:07:30
258	12	login	10.83.3.246	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36	\N	2026-03-06 11:07:43
259	\N	failed_login	10.83.4.33	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36	{"email":"posadmin@taxnest.com"}	2026-03-06 11:08:50
260	\N	failed_login	10.83.9.154	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36	{"email":"8612580zur@gmail.com"}	2026-03-06 11:17:30
261	11	login	10.83.9.154	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36	\N	2026-03-06 11:18:39
262	11	login	10.83.8.132	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36	\N	2026-03-06 12:05:21
263	11	login	10.83.4.33	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36	\N	2026-03-06 14:26:36
264	11	login	10.83.14.26	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36	\N	2026-03-06 14:29:46
265	11	login	10.83.6.27	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36	\N	2026-03-06 16:07:00
266	11	login	10.83.6.27	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36	\N	2026-03-06 16:08:52
267	\N	failed_login	10.83.14.26	Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 Replit-Bonsai/2.170.0 (iOS 26.3)	{"email":"posadmin@taxnest.com"}	2026-03-06 16:20:45
268	\N	failed_login	10.83.1.154	Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 Replit-Bonsai/2.170.0 (iOS 26.3)	{"email":"posadmin@taxnest.com"}	2026-03-06 16:21:43
269	1	login	10.83.9.33	Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 Replit-Bonsai/2.170.0 (iOS 26.3)	\N	2026-03-06 16:22:22
270	\N	failed_login	10.83.3.246	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36	{"email":"admin@test.com"}	2026-03-06 16:25:15
271	1	login	10.83.3.246	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36	\N	2026-03-06 16:25:26
272	\N	failed_login	10.83.10.64	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36	{"email":"admin@test.com"}	2026-03-06 16:27:39
273	\N	failed_login	10.83.10.64	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36	{"email":"admin@test.com"}	2026-03-06 16:27:48
274	1	login	10.83.10.64	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36	\N	2026-03-06 16:27:58
275	\N	failed_login	10.83.3.246	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36	{"email":"admin@test.com"}	2026-03-06 16:29:57
276	1	login	10.83.3.246	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36	\N	2026-03-06 16:30:10
277	\N	failed_login	10.83.14.26	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36	{"email":"admin@test.com"}	2026-03-06 16:33:49
278	1	login	10.83.2.137	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36	\N	2026-03-06 16:34:50
279	1	login	10.83.2.149	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36	\N	2026-03-07 08:00:24
280	11	login	10.83.9.49	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36	\N	2026-03-07 08:12:46
281	11	login	10.83.2.149	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36	\N	2026-03-07 08:13:51
282	1	login	10.83.9.49	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36	\N	2026-03-07 09:49:37
283	1	login	10.83.4.49	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36	\N	2026-03-07 09:52:27
284	1	login	10.83.5.4	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36	\N	2026-03-07 18:01:41
285	11	login	10.83.9.49	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36	\N	2026-03-07 18:24:06
286	11	login	10.83.0.25	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36	\N	2026-03-07 18:31:15
287	11	login	10.83.0.25	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36	\N	2026-03-07 18:48:08
288	11	login	10.83.8.61	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36	\N	2026-03-09 09:09:46
289	1	login	10.83.5.60	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36	\N	2026-03-09 09:11:14
290	11	login	10.83.13.104	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36	\N	2026-03-09 09:34:48
291	13	login	10.83.8.61	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36	\N	2026-03-09 12:48:29
292	13	login	10.83.7.81	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36	\N	2026-03-09 13:00:23
293	13	login	10.83.10.104	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36	\N	2026-03-09 13:03:04
294	13	login	10.83.8.61	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36	\N	2026-03-09 13:17:20
295	13	login	10.83.10.14	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36	\N	2026-03-19 08:31:54
296	13	login	10.83.11.55	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36	\N	2026-03-19 08:37:44
297	13	login	10.83.6.200	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36	\N	2026-03-19 08:49:45
298	13	login	10.83.14.138	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36	\N	2026-03-19 08:52:30
299	13	login	10.83.6.200	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36	\N	2026-03-19 09:54:38
300	13	login	10.83.7.165	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36	\N	2026-03-19 10:03:25
301	13	login	10.83.7.198	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36	\N	2026-03-19 10:10:46
302	13	login	10.83.0.162	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36	\N	2026-03-19 10:14:55
303	13	login	10.83.3.75	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36	\N	2026-03-19 10:17:42
304	13	login	10.83.7.165	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36	\N	2026-03-19 10:18:34
305	11	login	10.83.1.92	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36	\N	2026-03-19 10:39:28
306	13	login	10.83.8.24	Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Mobile/15E148 Safari/604.1	\N	2026-03-19 14:36:29
307	13	login	10.83.11.55	Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Mobile/15E148 Safari/604.1	\N	2026-03-19 14:36:50
308	13	login	10.83.14.226	Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Mobile/15E148 Safari/604.1	\N	2026-03-19 14:42:59
309	13	login	10.83.10.14	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36	\N	2026-03-19 14:51:04
310	13	login	10.83.4.33	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36	\N	2026-03-19 14:57:06
311	13	login	10.83.8.24	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36	\N	2026-03-19 15:00:44
312	13	login	10.83.8.24	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36	\N	2026-03-19 15:02:05
313	13	login	10.83.7.205	Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Mobile/15E148 Safari/604.1	\N	2026-03-19 15:05:23
314	13	login	10.83.7.165	Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Mobile/15E148 Safari/604.1	\N	2026-03-19 15:05:55
315	13	login	10.83.11.55	Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Mobile/15E148 Safari/604.1	\N	2026-03-19 15:06:39
316	13	login	10.83.14.138	Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.3 Mobile/15E148 Safari/604.1	\N	2026-03-19 15:07:27
317	13	login	127.0.0.1	Symfony	\N	2026-03-19 15:09:43
318	13	login	127.0.0.1	Symfony	\N	2026-03-19 15:10:07
319	13	login	127.0.0.1	Symfony	\N	2026-03-19 15:10:34
320	13	login	10.83.6.200	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36	\N	2026-03-19 15:12:05
321	13	login	10.83.2.152	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36	\N	2026-03-24 06:41:54
322	13	login	10.83.10.62	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36	\N	2026-03-24 06:44:47
323	13	login	10.83.10.62	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36	\N	2026-03-24 06:59:54
324	13	login	10.83.10.62	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36	\N	2026-03-24 07:01:11
325	13	login	10.83.4.86	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36	\N	2026-03-24 07:56:00
326	13	login	10.83.10.62	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36	\N	2026-03-24 08:13:12
327	13	login	10.83.10.62	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36	\N	2026-03-24 08:14:58
328	13	login	10.83.10.62	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36	\N	2026-03-24 08:19:28
329	13	login	10.83.2.152	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36	\N	2026-03-24 08:22:53
330	13	login	10.83.2.152	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36	\N	2026-03-24 08:23:06
331	13	login	10.83.9.85	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36	\N	2026-03-24 08:23:27
332	13	login	10.83.6.102	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36	\N	2026-03-24 08:25:28
333	13	login	10.83.5.129	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36	\N	2026-03-24 08:27:37
334	13	login	10.83.6.102	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36	\N	2026-03-24 08:29:11
335	13	login	10.83.6.102	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36	\N	2026-03-24 08:30:48
336	13	login	10.83.5.129	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36	\N	2026-03-24 08:31:11
337	13	login	10.83.6.102	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36	\N	2026-03-24 08:31:24
338	13	login	10.83.1.215	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36	\N	2026-03-24 08:32:27
339	13	login	10.83.4.87	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36	\N	2026-03-24 08:41:13
340	13	login	10.83.5.129	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36	\N	2026-03-24 08:41:35
341	13	login	10.83.5.129	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36	\N	2026-03-24 08:42:18
342	13	login	10.83.4.87	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36	\N	2026-03-24 08:43:02
343	13	login	10.83.5.129	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36	\N	2026-03-24 08:44:22
344	14	login	10.83.5.129	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36	\N	2026-03-24 08:48:04
345	14	login	10.83.5.129	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36	\N	2026-03-24 08:52:39
346	13	login	10.83.5.129	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36	\N	2026-03-24 08:53:06
347	14	login	10.83.5.129	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36	\N	2026-03-24 08:56:58
348	13	login	10.83.5.129	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36	\N	2026-03-24 08:59:40
349	14	login	10.83.6.102	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36	\N	2026-03-24 09:00:48
350	14	login	10.83.5.129	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36	\N	2026-03-24 09:02:44
351	13	login	10.83.8.94	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36	\N	2026-03-24 09:06:42
352	14	login	10.83.10.62	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36	\N	2026-03-24 09:08:10
353	14	login	10.83.8.94	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36	\N	2026-03-24 09:09:02
354	14	login	10.83.4.87	Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/146.0.0.0 Safari/537.36	\N	2026-03-24 09:09:18
355	13	login	10.83.6.102	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36	\N	2026-03-24 09:10:44
356	13	login	10.83.12.80	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36	\N	2026-03-24 09:16:29
357	13	login	10.83.5.129	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36	\N	2026-03-24 09:19:05
358	14	login	10.83.5.129	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36	\N	2026-03-24 09:19:50
359	13	login	10.83.1.215	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36	\N	2026-03-24 09:24:43
360	14	login	10.83.1.215	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36	\N	2026-03-24 09:26:13
361	13	login	10.83.2.152	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36	\N	2026-03-24 09:30:53
362	\N	login	127.0.0.1	curl/8.14.1	\N	2026-03-24 09:35:46
363	13	login	10.83.2.152	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36	\N	2026-03-24 09:36:55
364	13	login	10.83.9.85	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36	\N	2026-03-24 09:48:53
365	14	login	10.83.9.85	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36	\N	2026-03-24 09:52:28
366	14	login	10.83.2.152	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36	\N	2026-03-24 09:54:16
367	1	login	10.83.9.85	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36	\N	2026-03-24 09:56:14
368	13	login	10.83.0.58	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36	\N	2026-03-24 09:59:26
369	1	login	10.83.13.14	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36	\N	2026-03-24 10:02:51
370	14	login	10.83.5.129	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36	\N	2026-03-24 10:16:11
371	13	login	10.83.14.67	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36	\N	2026-03-24 10:16:41
372	13	login	10.83.11.152	Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:148.0) Gecko/20100101 Firefox/148.0	\N	2026-03-24 10:18:47
373	14	login	10.83.11.152	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36	\N	2026-03-24 10:19:55
374	14	login	10.83.10.62	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36	\N	2026-03-24 10:21:48
375	13	login	10.83.8.94	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36	\N	2026-03-24 10:22:22
376	13	login	10.83.11.152	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36	\N	2026-03-24 10:26:44
377	14	login	10.83.6.102	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36	\N	2026-03-24 10:27:12
378	14	login	10.83.9.85	Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:148.0) Gecko/20100101 Firefox/148.0	\N	2026-03-24 10:36:36
379	14	login	10.83.9.85	Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:148.0) Gecko/20100101 Firefox/148.0	\N	2026-03-24 10:36:46
380	14	login	10.83.2.160	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36	\N	2026-03-24 10:39:14
381	14	login	10.83.0.58	Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:148.0) Gecko/20100101 Firefox/148.0	\N	2026-03-24 10:50:09
382	14	login	10.83.0.58	Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:148.0) Gecko/20100101 Firefox/148.0	\N	2026-03-24 10:50:09
383	14	login	10.83.0.58	Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:148.0) Gecko/20100101 Firefox/148.0	\N	2026-03-24 10:50:23
384	14	login	10.83.0.58	Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:148.0) Gecko/20100101 Firefox/148.0	\N	2026-03-24 10:50:26
385	14	login	10.83.0.58	Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:148.0) Gecko/20100101 Firefox/148.0	\N	2026-03-24 10:50:44
386	14	login	10.83.0.58	Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:148.0) Gecko/20100101 Firefox/148.0	\N	2026-03-24 10:50:48
387	14	login	10.83.0.58	Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:148.0) Gecko/20100101 Firefox/148.0	\N	2026-03-24 10:50:51
388	14	login	10.83.0.58	Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:148.0) Gecko/20100101 Firefox/148.0	\N	2026-03-24 10:51:14
389	14	login	10.83.0.58	Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:148.0) Gecko/20100101 Firefox/148.0	\N	2026-03-24 10:52:03
390	14	login	10.83.0.58	Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:148.0) Gecko/20100101 Firefox/148.0	\N	2026-03-24 10:52:14
391	14	login	10.83.0.58	Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:148.0) Gecko/20100101 Firefox/148.0	\N	2026-03-24 10:52:22
392	14	login	10.83.0.58	Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:148.0) Gecko/20100101 Firefox/148.0	\N	2026-03-24 10:52:28
393	14	login	10.83.0.58	Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:148.0) Gecko/20100101 Firefox/148.0	\N	2026-03-24 10:54:19
394	13	login	10.83.14.67	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36	\N	2026-03-24 10:54:23
395	14	login	10.83.0.58	Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:148.0) Gecko/20100101 Firefox/148.0	\N	2026-03-24 10:54:51
396	14	login	10.83.0.58	Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:148.0) Gecko/20100101 Firefox/148.0	\N	2026-03-24 10:55:18
397	14	login	10.83.0.58	Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:148.0) Gecko/20100101 Firefox/148.0	\N	2026-03-24 10:55:22
398	14	login	10.83.14.67	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36	\N	2026-03-24 10:55:25
399	14	login	10.83.8.94	Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:148.0) Gecko/20100101 Firefox/148.0	\N	2026-03-24 10:57:20
400	13	login	10.83.2.160	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36	\N	2026-03-24 11:01:46
401	14	login	10.83.2.160	Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:148.0) Gecko/20100101 Firefox/148.0	\N	2026-03-24 11:04:38
402	14	login	10.83.14.67	Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:148.0) Gecko/20100101 Firefox/148.0	\N	2026-03-24 11:05:08
403	14	login	10.83.6.102	Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:148.0) Gecko/20100101 Firefox/148.0	\N	2026-03-24 11:05:51
404	14	login	10.83.12.80	Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:148.0) Gecko/20100101 Firefox/148.0	\N	2026-03-24 11:05:57
405	14	login	10.83.12.80	Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:148.0) Gecko/20100101 Firefox/148.0	\N	2026-03-24 11:06:01
406	14	login	10.83.4.90	Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:148.0) Gecko/20100101 Firefox/148.0	\N	2026-03-24 11:06:12
407	13	login	10.83.13.14	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36	\N	2026-03-24 11:08:34
408	14	login	10.83.8.94	Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:148.0) Gecko/20100101 Firefox/148.0	\N	2026-03-24 11:16:30
409	14	login	10.83.3.232	Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:148.0) Gecko/20100101 Firefox/148.0	\N	2026-03-24 11:23:38
410	14	login	10.83.8.94	Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:148.0) Gecko/20100101 Firefox/148.0	\N	2026-03-24 11:25:01
411	14	login	10.83.4.87	Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:148.0) Gecko/20100101 Firefox/148.0	\N	2026-03-24 11:25:34
412	14	login	10.83.12.80	Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:148.0) Gecko/20100101 Firefox/148.0	\N	2026-03-24 11:30:33
413	14	login	10.83.12.80	Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:148.0) Gecko/20100101 Firefox/148.0	\N	2026-03-24 11:31:16
414	14	login	10.83.12.80	Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:148.0) Gecko/20100101 Firefox/148.0	\N	2026-03-24 11:31:19
415	14	login	10.83.12.80	Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:148.0) Gecko/20100101 Firefox/148.0	\N	2026-03-24 11:31:22
416	14	login	10.83.12.80	Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:148.0) Gecko/20100101 Firefox/148.0	\N	2026-03-24 11:32:37
417	14	login	10.83.5.129	Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:148.0) Gecko/20100101 Firefox/148.0	\N	2026-03-24 16:34:50
418	14	login	10.83.5.129	Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:148.0) Gecko/20100101 Firefox/148.0	\N	2026-03-24 16:34:59
419	14	login	10.83.8.94	Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:148.0) Gecko/20100101 Firefox/148.0	\N	2026-03-24 16:35:11
420	14	login	10.83.8.94	Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:148.0) Gecko/20100101 Firefox/148.0	\N	2026-03-24 16:35:15
421	14	login	10.83.8.94	Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:148.0) Gecko/20100101 Firefox/148.0	\N	2026-03-24 16:35:18
422	14	login	10.83.8.94	Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:148.0) Gecko/20100101 Firefox/148.0	\N	2026-03-24 16:35:23
423	14	login	10.83.3.232	Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:148.0) Gecko/20100101 Firefox/148.0	\N	2026-03-24 16:40:08
424	14	login	10.83.3.232	Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:148.0) Gecko/20100101 Firefox/148.0	\N	2026-03-24 16:40:11
425	14	login	10.83.0.58	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36	\N	2026-03-24 16:40:22
426	14	login	10.83.2.152	Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:148.0) Gecko/20100101 Firefox/148.0	\N	2026-03-24 16:41:44
427	14	login	10.83.13.14	Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:148.0) Gecko/20100101 Firefox/148.0	\N	2026-03-24 16:41:48
428	14	login	10.83.13.14	Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:148.0) Gecko/20100101 Firefox/148.0	\N	2026-03-24 16:41:58
429	14	login	10.83.14.67	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36	\N	2026-03-24 16:44:25
430	13	login	10.83.14.67	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36	\N	2026-03-24 16:45:08
431	14	login	10.83.5.129	Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:148.0) Gecko/20100101 Firefox/148.0	\N	2026-03-24 16:45:24
432	14	login	10.83.5.129	Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:148.0) Gecko/20100101 Firefox/148.0	\N	2026-03-24 16:45:30
433	14	login	10.83.5.129	Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:148.0) Gecko/20100101 Firefox/148.0	\N	2026-03-24 16:45:32
434	14	login	10.83.14.67	Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:148.0) Gecko/20100101 Firefox/148.0	\N	2026-03-24 16:45:35
435	14	login	10.83.0.58	Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:148.0) Gecko/20100101 Firefox/148.0	\N	2026-03-24 16:45:42
436	14	login	10.83.13.14	Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:148.0) Gecko/20100101 Firefox/148.0	\N	2026-03-24 16:45:45
437	14	login	10.83.13.14	Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:148.0) Gecko/20100101 Firefox/148.0	\N	2026-03-24 16:45:48
438	14	login	10.83.6.102	Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:148.0) Gecko/20100101 Firefox/148.0	\N	2026-03-24 16:46:00
439	14	login	10.83.13.14	Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:148.0) Gecko/20100101 Firefox/148.0	\N	2026-03-24 16:46:03
440	14	login	10.83.14.67	Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:148.0) Gecko/20100101 Firefox/148.0	\N	2026-03-24 16:47:34
441	14	login	10.83.14.67	Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:148.0) Gecko/20100101 Firefox/148.0	\N	2026-03-24 16:47:35
442	14	login	10.83.9.85	Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:148.0) Gecko/20100101 Firefox/148.0	\N	2026-03-24 16:47:39
443	14	login	10.83.9.85	Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:148.0) Gecko/20100101 Firefox/148.0	\N	2026-03-24 16:47:44
444	14	login	10.83.8.94	Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:148.0) Gecko/20100101 Firefox/148.0	\N	2026-03-24 16:47:53
445	14	login	10.83.8.94	Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:148.0) Gecko/20100101 Firefox/148.0	\N	2026-03-24 16:47:58
446	14	login	10.83.8.94	Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:148.0) Gecko/20100101 Firefox/148.0	\N	2026-03-24 16:48:01
447	14	login	10.83.8.94	Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:148.0) Gecko/20100101 Firefox/148.0	\N	2026-03-24 16:48:06
448	14	login	10.83.8.94	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36	\N	2026-03-24 16:48:14
449	14	login	10.83.4.87	Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:148.0) Gecko/20100101 Firefox/148.0	\N	2026-03-24 16:48:34
450	14	login	10.83.2.152	Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:148.0) Gecko/20100101 Firefox/148.0	\N	2026-03-24 16:48:43
451	14	login	10.83.9.85	Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:148.0) Gecko/20100101 Firefox/148.0	\N	2026-03-24 16:50:45
452	14	login	10.83.3.232	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36	\N	2026-03-24 16:51:04
453	14	login	10.83.2.152	Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:148.0) Gecko/20100101 Firefox/148.0	\N	2026-03-24 16:54:09
454	14	login	10.83.11.152	Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:148.0) Gecko/20100101 Firefox/148.0	\N	2026-03-24 16:54:14
455	14	login	10.83.11.152	Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:148.0) Gecko/20100101 Firefox/148.0	\N	2026-03-24 16:54:22
456	14	login	10.83.8.94	Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:148.0) Gecko/20100101 Firefox/148.0	\N	2026-03-24 16:54:23
457	14	login	10.83.11.152	Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:148.0) Gecko/20100101 Firefox/148.0	\N	2026-03-24 16:54:24
458	14	login	10.83.11.152	Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:148.0) Gecko/20100101 Firefox/148.0	\N	2026-03-24 16:54:38
459	14	login	10.83.11.152	Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:148.0) Gecko/20100101 Firefox/148.0	\N	2026-03-24 16:54:45
460	14	login	10.83.2.152	Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:148.0) Gecko/20100101 Firefox/148.0	\N	2026-03-24 16:54:52
461	14	login	10.83.11.152	Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:148.0) Gecko/20100101 Firefox/148.0	\N	2026-03-24 16:56:52
462	14	login	10.83.10.62	Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:148.0) Gecko/20100101 Firefox/148.0	\N	2026-03-24 16:56:56
463	14	login	10.83.10.62	Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:148.0) Gecko/20100101 Firefox/148.0	\N	2026-03-24 16:56:57
464	14	login	10.83.10.62	Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:148.0) Gecko/20100101 Firefox/148.0	\N	2026-03-24 16:57:02
465	14	login	10.83.2.152	Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:148.0) Gecko/20100101 Firefox/148.0	\N	2026-03-24 16:57:02
466	14	login	10.83.2.152	Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:148.0) Gecko/20100101 Firefox/148.0	\N	2026-03-24 16:57:06
467	14	login	10.83.10.62	Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:148.0) Gecko/20100101 Firefox/148.0	\N	2026-03-24 16:57:06
468	14	login	10.83.10.62	Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:148.0) Gecko/20100101 Firefox/148.0	\N	2026-03-24 16:57:14
469	14	login	10.83.2.152	Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:148.0) Gecko/20100101 Firefox/148.0	\N	2026-03-24 16:57:14
470	14	login	10.83.2.152	Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:148.0) Gecko/20100101 Firefox/148.0	\N	2026-03-24 16:57:28
471	14	login	10.83.2.152	Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:148.0) Gecko/20100101 Firefox/148.0	\N	2026-03-24 16:57:31
472	14	login	10.83.2.152	Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:148.0) Gecko/20100101 Firefox/148.0	\N	2026-03-24 16:58:12
473	14	login	10.83.2.152	Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:148.0) Gecko/20100101 Firefox/148.0	\N	2026-03-24 16:58:21
474	14	login	10.83.2.152	Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:148.0) Gecko/20100101 Firefox/148.0	\N	2026-03-24 16:58:37
475	14	login	10.83.2.152	Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:148.0) Gecko/20100101 Firefox/148.0	\N	2026-03-24 16:58:49
476	14	login	10.83.2.152	Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:148.0) Gecko/20100101 Firefox/148.0	\N	2026-03-24 16:58:56
477	14	login	10.83.10.62	Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:148.0) Gecko/20100101 Firefox/148.0	\N	2026-03-24 17:01:29
478	13	login	10.83.8.94	Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36	\N	2026-03-24 17:04:35
479	14	login	10.83.0.58	Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:148.0) Gecko/20100101 Firefox/148.0	\N	2026-03-24 17:05:11
480	14	login	10.83.9.85	Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:148.0) Gecko/20100101 Firefox/148.0	\N	2026-03-24 17:05:15
481	14	login	10.83.9.85	Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:148.0) Gecko/20100101 Firefox/148.0	\N	2026-03-24 17:05:24
482	14	login	10.83.9.85	Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:148.0) Gecko/20100101 Firefox/148.0	\N	2026-03-24 17:05:29
483	14	login	10.83.9.85	Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:148.0) Gecko/20100101 Firefox/148.0	\N	2026-03-24 17:05:35
484	14	login	10.83.9.85	Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:148.0) Gecko/20100101 Firefox/148.0	\N	2026-03-24 17:05:41
485	14	login	10.83.6.102	Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:148.0) Gecko/20100101 Firefox/148.0	\N	2026-03-24 17:09:13
486	14	login	10.83.10.62	Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:148.0) Gecko/20100101 Firefox/148.0	\N	2026-03-24 17:09:17
487	14	login	10.83.10.62	Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:148.0) Gecko/20100101 Firefox/148.0	\N	2026-03-24 17:09:40
488	14	login	10.83.10.62	Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:148.0) Gecko/20100101 Firefox/148.0	\N	2026-03-24 17:09:44
489	14	login	10.83.10.62	Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:148.0) Gecko/20100101 Firefox/148.0	\N	2026-03-24 17:10:01
490	14	login	10.83.3.232	Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:148.0) Gecko/20100101 Firefox/148.0	\N	2026-03-24 17:14:00
491	14	login	10.83.2.152	Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:148.0) Gecko/20100101 Firefox/148.0	\N	2026-03-24 17:14:05
492	14	login	10.83.2.152	Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:148.0) Gecko/20100101 Firefox/148.0	\N	2026-03-24 17:14:16
493	14	login	10.83.2.152	Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:148.0) Gecko/20100101 Firefox/148.0	\N	2026-03-24 17:15:13
\.


--
-- Data for Name: sessions; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.sessions (id, user_id, ip_address, user_agent, payload, last_activity) FROM stdin;
\.


--
-- Data for Name: special_sro_rules; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.special_sro_rules (id, hs_code, schedule_type, sro_number, serial_no, applicable_sector, applicable_province, concessionary_rate, description, effective_from, effective_until, is_active, created_at, updated_at) FROM stdin;
1	02011000	exempt	SRO 551(I)/2008	1	Food	\N	0.00	Fresh/chilled bovine carcasses - Exempt from sales tax under 6th Schedule	2026-01-01	\N	t	2026-02-12 11:34:28	2026-02-12 11:34:28
2	02023000	exempt	SRO 551(I)/2008	1	Food	\N	0.00	Boneless frozen bovine meat - Exempt under 6th Schedule	2026-01-01	\N	t	2026-02-12 11:34:29	2026-02-12 11:34:29
3	02071100	exempt	SRO 551(I)/2008	2	Food	\N	0.00	Fresh/chilled whole chicken - Exempt under 6th Schedule	2026-01-01	\N	t	2026-02-12 11:34:29	2026-02-12 11:34:29
4	04011000	exempt	SRO 551(I)/2008	3	Dairy	\N	0.00	Milk (fat <=1%) - Exempt under 6th Schedule	2026-01-01	\N	t	2026-02-12 11:34:29	2026-02-12 11:34:29
5	04012000	exempt	SRO 551(I)/2008	3	Dairy	\N	0.00	Milk (fat 1-6%) - Exempt under 6th Schedule	2026-01-01	\N	t	2026-02-12 11:34:29	2026-02-12 11:34:29
6	04014000	exempt	SRO 551(I)/2008	3	Dairy	\N	0.00	Milk (fat >6%) - Exempt under 6th Schedule	2026-01-01	\N	t	2026-02-12 11:34:29	2026-02-12 11:34:29
7	04021000	exempt	SRO 551(I)/2008	4	Dairy	\N	0.00	Milk powder (<=1.5% fat) - Exempt under 6th Schedule	2026-01-01	\N	t	2026-02-12 11:34:29	2026-02-12 11:34:29
8	04051000	exempt	SRO 551(I)/2008	5	Dairy	\N	0.00	Butter - Exempt under 6th Schedule	2026-01-01	\N	t	2026-02-12 11:34:29	2026-02-12 11:34:29
9	04061000	exempt	SRO 551(I)/2008	6	Dairy	\N	0.00	Fresh cheese/curd - Exempt under 6th Schedule	2026-01-01	\N	t	2026-02-12 11:34:29	2026-02-12 11:34:29
10	07019000	exempt	SRO 551(I)/2008	7	Agriculture	\N	0.00	Potatoes - Exempt under 6th Schedule	2026-01-01	\N	t	2026-02-12 11:34:29	2026-02-12 11:34:29
11	07031000	exempt	SRO 551(I)/2008	8	Agriculture	\N	0.00	Onions and shallots - Exempt under 6th Schedule	2026-01-01	\N	t	2026-02-12 11:34:29	2026-02-12 11:34:29
12	07032000	exempt	SRO 551(I)/2008	8	Agriculture	\N	0.00	Garlic - Exempt under 6th Schedule	2026-01-01	\N	t	2026-02-12 11:34:29	2026-02-12 11:34:29
13	10011900	exempt	SRO 551(I)/2008	9	Agriculture	\N	0.00	Wheat - Exempt under 6th Schedule	2026-01-01	\N	t	2026-02-12 11:34:29	2026-02-12 11:34:29
14	10063090	exempt	SRO 551(I)/2008	10	Agriculture	\N	0.00	Rice - Exempt under 6th Schedule	2026-01-01	\N	t	2026-02-12 11:34:29	2026-02-12 11:34:29
15	11010010	exempt	SRO 551(I)/2008	11	Food	\N	0.00	Wheat flour (atta/maida) - Exempt under 6th Schedule	2026-01-01	\N	t	2026-02-12 11:34:29	2026-02-12 11:34:29
16	15079000	exempt	SRO 551(I)/2008	12	Food	\N	0.00	Soyabean oil - Exempt under 6th Schedule	2026-01-01	\N	t	2026-02-12 11:34:29	2026-02-12 11:34:29
17	17011300	exempt	SRO 551(I)/2008	13	Food	\N	0.00	Cane sugar - Exempt under 6th Schedule	2026-01-01	\N	t	2026-02-12 11:34:29	2026-02-12 11:34:29
18	30049099	exempt	SRO 551(I)/2008	14	Pharma	\N	0.00	Medicaments/pharmaceutical products - Exempt under 6th Schedule	2026-01-01	\N	t	2026-02-12 11:34:29	2026-02-12 11:34:29
19	30059000	exempt	SRO 551(I)/2008	15	Pharma	\N	0.00	Surgical dressings/bandages - Exempt under 6th Schedule	2026-01-01	\N	t	2026-02-12 11:34:29	2026-02-12 11:34:29
20	48201000	exempt	SRO 551(I)/2008	16	Stationery	\N	0.00	Exercise books/notebooks - Exempt under 6th Schedule	2026-01-01	\N	t	2026-02-12 11:34:29	2026-02-12 11:34:29
21	49011000	exempt	SRO 551(I)/2008	17	Stationery	\N	0.00	Printed books/brochures - Exempt under 6th Schedule	2026-01-01	\N	t	2026-02-12 11:34:29	2026-02-12 11:34:29
22	49021000	exempt	SRO 551(I)/2008	18	Media	\N	0.00	Newspapers/journals - Exempt under 6th Schedule	2026-01-01	\N	t	2026-02-12 11:34:29	2026-02-12 11:34:29
23	52010000	zero_rated	SRO 1125(I)/2011	1	Textile	\N	0.00	Raw cotton - Zero rated for textile export sector	2026-01-01	\N	t	2026-02-12 11:34:29	2026-02-12 11:34:29
24	52030000	zero_rated	SRO 1125(I)/2011	2	Textile	\N	0.00	Cotton waste - Zero rated for textile export	2026-01-01	\N	t	2026-02-12 11:34:29	2026-02-12 11:34:29
25	52041100	zero_rated	SRO 1125(I)/2011	3	Textile	\N	0.00	Cotton sewing thread - Zero rated for textile	2026-01-01	\N	t	2026-02-12 11:34:29	2026-02-12 11:34:29
26	52051100	zero_rated	SRO 1125(I)/2011	4	Textile	\N	0.00	Cotton yarn (uncombed, single) - Zero rated	2026-01-01	\N	t	2026-02-12 11:34:29	2026-02-12 11:34:29
27	52081100	zero_rated	SRO 1125(I)/2011	5	Textile	\N	0.00	Unbleached cotton fabric - Zero rated	2026-01-01	\N	t	2026-02-12 11:34:29	2026-02-12 11:34:29
28	54011000	zero_rated	SRO 1125(I)/2011	6	Textile	\N	0.00	Synthetic filament yarn - Zero rated for textile	2026-01-01	\N	t	2026-02-12 11:34:29	2026-02-12 11:34:29
29	55032000	zero_rated	SRO 1125(I)/2011	7	Textile	\N	0.00	Polyester staple fibers - Zero rated	2026-01-01	\N	t	2026-02-12 11:34:29	2026-02-12 11:34:29
30	61091000	zero_rated	SRO 1125(I)/2011	8	Textile	\N	0.00	T-shirts/knitted garments - Zero rated for export	2026-01-01	\N	t	2026-02-12 11:34:29	2026-02-12 11:34:29
31	62034200	zero_rated	SRO 1125(I)/2011	9	Textile	\N	0.00	Cotton trousers/shorts - Zero rated for export	2026-01-01	\N	t	2026-02-12 11:34:29	2026-02-12 11:34:29
32	63031200	zero_rated	SRO 1125(I)/2011	10	Textile	\N	0.00	Knitted curtains/furnishing - Zero rated	2026-01-01	\N	t	2026-02-12 11:34:29	2026-02-12 11:34:29
33	41012000	zero_rated	SRO 1125(I)/2011	11	Leather	\N	0.00	Raw hides/skins of bovine - Zero rated for leather export	2026-01-01	\N	t	2026-02-12 11:34:29	2026-02-12 11:34:29
34	42021200	zero_rated	SRO 1125(I)/2011	12	Leather	\N	0.00	Leather trunks/suitcases - Zero rated for export	2026-01-01	\N	t	2026-02-12 11:34:29	2026-02-12 11:34:29
35	42032100	zero_rated	SRO 1125(I)/2011	13	Leather	\N	0.00	Leather gloves - Zero rated for export	2026-01-01	\N	t	2026-02-12 11:34:29	2026-02-12 11:34:29
36	64039900	zero_rated	SRO 1125(I)/2011	14	Leather	\N	0.00	Leather footwear - Zero rated for export	2026-01-01	\N	t	2026-02-12 11:34:29	2026-02-12 11:34:29
37	57011000	zero_rated	SRO 1125(I)/2011	15	Textile	\N	0.00	Carpets/rugs (knotted) - Zero rated for export	2026-01-01	\N	t	2026-02-12 11:34:29	2026-02-12 11:34:29
38	87032100	3rd_schedule	SRO 693(I)/2006	1	Automotive	\N	17.00	Motor vehicles (1000-1500cc) - 3rd Schedule, fixed tax at MRP	2026-01-01	\N	t	2026-02-12 11:34:29	2026-02-12 11:34:29
39	87032300	3rd_schedule	SRO 693(I)/2006	2	Automotive	\N	17.00	Motor vehicles (1500-3000cc) - 3rd Schedule at MRP	2026-01-01	\N	t	2026-02-12 11:34:29	2026-02-12 11:34:29
40	87032400	3rd_schedule	SRO 693(I)/2006	3	Automotive	\N	25.00	Motor vehicles (>3000cc) - 3rd Schedule at MRP	2026-01-01	\N	t	2026-02-12 11:34:29	2026-02-12 11:34:29
41	85171100	3rd_schedule	SRO 693(I)/2006	4	Electronics	\N	17.00	Mobile phones/smartphones - 3rd Schedule at MRP	2026-01-01	\N	t	2026-02-12 11:34:29	2026-02-12 11:34:29
42	84182100	3rd_schedule	SRO 693(I)/2006	5	Electronics	\N	17.00	Refrigerators - 3rd Schedule at MRP	2026-01-01	\N	t	2026-02-12 11:34:29	2026-02-12 11:34:29
43	84501100	3rd_schedule	SRO 693(I)/2006	6	Electronics	\N	17.00	Washing machines - 3rd Schedule at MRP	2026-01-01	\N	t	2026-02-12 11:34:29	2026-02-12 11:34:29
44	85163200	3rd_schedule	SRO 693(I)/2006	7	Electronics	\N	17.00	Hair dryers/electric appliances - 3rd Schedule at MRP	2026-01-01	\N	t	2026-02-12 11:34:29	2026-02-12 11:34:29
45	84151000	3rd_schedule	SRO 693(I)/2006	8	Electronics	\N	17.00	Air conditioners (window/split) - 3rd Schedule at MRP	2026-01-01	\N	t	2026-02-12 11:34:29	2026-02-12 11:34:29
46	85287200	3rd_schedule	SRO 693(I)/2006	9	Electronics	\N	17.00	LED/LCD television sets - 3rd Schedule at MRP	2026-01-01	\N	t	2026-02-12 11:34:29	2026-02-12 11:34:29
47	27101990	reduced	SRO 648(I)/2013	1	Petroleum	\N	10.00	Light oils/petroleum - Reduced rate under SRO 648	2026-01-01	\N	t	2026-02-12 11:34:29	2026-02-12 11:34:29
48	27101220	reduced	SRO 648(I)/2013	2	Petroleum	\N	10.00	Motor spirit/petrol - Reduced rate	2026-01-01	\N	t	2026-02-12 11:34:29	2026-02-12 11:34:29
49	27101940	reduced	SRO 648(I)/2013	3	Petroleum	\N	10.00	High speed diesel oil - Reduced rate	2026-01-01	\N	t	2026-02-12 11:34:29	2026-02-12 11:34:29
50	27111200	reduced	SRO 648(I)/2013	4	Energy	\N	10.00	Propane (LPG) - Reduced rate under SRO 648	2026-01-01	\N	t	2026-02-12 11:34:29	2026-02-12 11:34:29
51	27112100	reduced	SRO 648(I)/2013	5	Energy	\N	10.00	Natural gas in gaseous state - Reduced rate	2026-01-01	\N	t	2026-02-12 11:34:29	2026-02-12 11:34:29
52	31021000	exempt	SRO 551(I)/2008	19	Agriculture	\N	0.00	Urea fertilizer - Exempt under 6th Schedule	2026-01-01	\N	t	2026-02-12 11:34:29	2026-02-12 11:34:29
53	31031100	exempt	SRO 551(I)/2008	20	Agriculture	\N	0.00	Superphosphate fertilizer - Exempt under 6th Schedule	2026-01-01	\N	t	2026-02-12 11:34:29	2026-02-12 11:34:29
54	31042000	exempt	SRO 551(I)/2008	21	Agriculture	\N	0.00	Potassic fertilizer - Exempt under 6th Schedule	2026-01-01	\N	t	2026-02-12 11:34:29	2026-02-12 11:34:29
55	84713010	reduced	SRO 655(I)/2007	1	IT	\N	5.00	Laptop computers - Reduced rate for IT sector	2026-01-01	\N	t	2026-02-12 11:34:29	2026-02-12 11:34:29
56	84714100	reduced	SRO 655(I)/2007	2	IT	\N	5.00	Desktop computers - Reduced rate for IT	2026-01-01	\N	t	2026-02-12 11:34:29	2026-02-12 11:34:29
57	85414020	exempt	SRO 551(I)/2008	22	Energy	\N	0.00	Solar panels/photovoltaic cells - Exempt under 6th Schedule	2026-01-01	\N	t	2026-02-12 11:34:29	2026-02-12 11:34:29
58	85044090	exempt	SRO 551(I)/2008	23	Energy	\N	0.00	Solar inverters - Exempt under 6th Schedule	2026-01-01	\N	t	2026-02-12 11:34:29	2026-02-12 11:34:29
59	48191000	reduced	SRO 1125(I)/2011	16	Packaging	\N	10.00	Carton boxes/packaging - Reduced rate for export packaging	2026-01-01	\N	t	2026-02-12 11:34:29	2026-02-12 11:34:29
60	39232100	reduced	SRO 1125(I)/2011	17	Packaging	\N	10.00	Plastic bags/sacks for packaging - Reduced rate	2026-01-01	\N	t	2026-02-12 11:34:29	2026-02-12 11:34:29
61	25232100	standard	SRO 350(I)/2024	1	Construction	\N	18.00	White/grey cement - Standard rate with FED	2026-01-01	\N	t	2026-02-12 11:34:29	2026-02-12 11:34:29
62	25232900	standard	SRO 350(I)/2024	2	Construction	\N	18.00	Portland cement - Standard rate with FED	2026-01-01	\N	t	2026-02-12 11:34:29	2026-02-12 11:34:29
63	72131000	standard	SRO 350(I)/2024	3	Construction	\N	18.00	Iron/steel bars (hot-rolled) - Standard rate	2026-01-01	\N	t	2026-02-12 11:34:29	2026-02-12 11:34:29
64	72142000	standard	SRO 350(I)/2024	4	Construction	\N	18.00	Iron/steel bars (cold-formed) - Standard rate	2026-01-01	\N	t	2026-02-12 11:34:29	2026-02-12 11:34:29
\.


--
-- Data for Name: subscription_invoices; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.subscription_invoices (id, subscription_id, company_id, amount, status, due_date, paid_at, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: subscription_payments; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.subscription_payments (id, subscription_invoice_id, amount, payment_method, transaction_ref, paid_at, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: subscriptions; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.subscriptions (id, company_id, pricing_plan_id, start_date, end_date, active, created_at, updated_at, trial_ends_at, billing_cycle, discount_percent, final_price) FROM stdin;
5	1	8	2026-02-01	2026-03-01	t	2026-02-11 13:46:15	2026-02-11 13:46:15	\N	monthly	0.00	15000.00
6	2	6	2026-02-01	2026-03-01	t	2026-02-11 13:46:15	2026-02-11 13:46:15	\N	monthly	0.00	2999.00
7	3	5	2026-02-01	2026-03-01	t	2026-02-11 13:46:15	2026-02-11 13:46:15	\N	monthly	0.00	999.00
8	5	4	2026-02-13	2026-03-13	t	2026-02-13 05:03:24	2026-02-13 05:03:24	2026-02-27 05:03:24	monthly	0.00	\N
9	6	4	2026-02-13	2026-03-13	t	2026-02-13 05:03:52	2026-02-13 05:03:52	2026-02-27 05:03:52	monthly	0.00	\N
10	7	4	2026-02-13	2026-03-13	f	2026-02-13 05:12:05	2026-02-21 17:47:28	2026-02-27 05:12:05	monthly	0.00	\N
11	7	8	2026-02-21	2026-03-21	f	2026-02-21 17:47:28	2026-03-07 08:00:50	\N	monthly	0.00	\N
\.


--
-- Data for Name: suppliers; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.suppliers (id, company_id, name, ntn, cnic, phone, email, address, city, contact_person, notes, is_active, created_at, updated_at) FROM stdin;
\.


--
-- Data for Name: system_controls; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.system_controls (id, key, value, description, updated_by, created_at, updated_at) FROM stdin;
1	pra_submissions	enabled	Enable/disable PRA submissions globally	\N	2026-03-06 04:39:51	2026-03-06 04:39:51
2	pos_system	enabled	Enable/disable POS system globally	\N	2026-03-06 04:39:51	2026-03-06 04:39:51
3	maintenance_mode	disabled	Enable/disable maintenance mode	\N	2026-03-06 04:39:51	2026-03-06 04:39:51
4	new_registrations	enabled	Enable/disable new company registrations	\N	2026-03-06 04:39:51	2026-03-06 04:39:51
\.


--
-- Data for Name: system_settings; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.system_settings (id, key, value, description, created_at, updated_at) FROM stdin;
1	mom_spike_threshold	200	Month-over-month spike threshold percentage	2026-02-11 11:19:01	2026-02-11 11:19:01
2	tax_drop_threshold	60	Tax drop threshold percentage	2026-02-11 11:19:01	2026-02-11 11:19:01
3	critical_score_threshold	40	Critical compliance score threshold	2026-02-11 11:19:01	2026-02-11 11:19:01
4	stability_bonus_weight	10	Weight for stability bonus in scoring	2026-02-11 11:19:01	2026-02-11 11:19:01
5	demo_mode	false	Enable demo safety mode - disables real PRAL API calls	2026-02-11 11:46:17	2026-02-11 11:46:17
\.


--
-- Data for Name: users; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.users (id, name, email, email_verified_at, password, remember_token, company_id, created_at, updated_at, role, is_active, dark_mode, phone, username, pos_role) FROM stdin;
1	Super Admin	admin@test.com	\N	$2y$12$E6lIqXw0t7ZUl6bD1vJ8M.JkqYKaZpvu9O.mkMk45h5Z0fpFvIr6m	JS0eXk63fgLTjra3ZV1oQUi84dk38F1rRkiEQd370EhOzQnTnMR1uT4ap5Lr	\N	2026-02-11 10:22:40	2026-02-11 15:30:01	super_admin	t	\N	\N	\N	\N
13	MOHAMMAD RASHEED	malikchickenbroast@taxnest.com	\N	$2y$12$KEsWECNMaxSRyQvZXV1P2Osc6prjSArtHO5cw3FRZGoA9/K.doEwK	XEsMszfMlt7nAnmZ2QBno0XjAAcI2L3e09lAFJlEE594pORBuZudfPjWF5bv	13	2026-03-09 12:46:24	2026-03-24 08:10:53	company_admin	t	\N	\N	\N	pos_admin
14	aamir	aamir@gmail.com	\N	$2y$12$wvq5uIZCNWL4srSKxzCqOev4jnOD7uNtbhXjtZAb8jYZx3QXiMJQy	L5KeOZqGyGZabx69jIWaL14Vlo5N2qUhxZYc2Ja8Zw6O8sAN4dz4gKQEZmhO	13	2026-03-24 08:46:01	2026-03-24 10:35:54	employee	t	\N	\N	\N	pos_cashier
3	Jawad Employee	jawad@test.com	\N	$2y$12$.yD/TmOTt4VlHIWBz14jYuE4u5nzp0cvu3Rx3B5b/SMwO9TVwL9JO	\N	1	2026-02-11 10:22:41	2026-02-11 15:29:59	employee	t	\N	\N	\N	\N
6	New Employee	newemp@test.com	\N	$2y$12$U9X5YbxmQpCJq2ll20PzNuZX6sXgSRiePCX4UGiZzo0U3sI8VIt9K	\N	1	2026-02-11 12:33:02	2026-02-11 15:30:00	employee	t	\N	\N	\N	\N
10	ZIA UR REHMAN	8612580zur@gmail.com	\N	$2y$12$V5J.Zqjhp1CXTjAIsLZYJ.BKy3g/TCPFavN6L2DLeq6fruDJuTDMu	qzujfh66zBexLibHxBdjXUKSHKHyTIkimToPSnboQhzkud4WPxzmy0IJw5XW	7	2026-02-13 05:12:05	2026-02-13 11:59:11	company_admin	t	\N	\N	\N	pos_admin
5	Test Owner	testowner@example.com	\N	$2y$12$eVVYQ5DCrECg9Zb.44Svu.okNY07iLkcrqWRYu5vI/mjViZe0Dt/e	\N	3	2026-02-11 12:32:48	2026-03-05 18:17:04	company_admin	t	\N	\N	\N	pos_admin
9	FBR Test Admin	fbrtestadmin@testing.com	\N	$2y$12$f/2K33tAnwMnD1Ya207oJOpjwU.gqrcuff2YV2tNyRKOR65Y8.lam	\N	6	2026-02-13 05:03:52	2026-02-13 05:03:52	company_admin	t	\N	\N	\N	pos_admin
11	POS Admin	posadmin@taxnest.com	\N	$2y$12$RKlJGGDvGWCDAqB6OKwgn.U8tEZmZHxNt0MHw9LGU0BDuBmTosrFK	\N	11	2026-03-06 08:25:32	2026-03-06 10:47:55	company_admin	t	\N	\N	\N	pos_admin
12	Test Owner	test@testtrading.pk	\N	$2y$12$.CKoecOHhFHj3hu0t7ZCLuisxT3OyjGsAqMfhp0gwMkrYTC67mrum	\N	12	2026-03-06 10:51:53	2026-03-06 10:51:53	company_admin	t	\N	03111234567	testowner	pos_admin
7	NewTestUser	newtest@test.com	\N	$2y$12$GrH4/wCc4d2.al2C8X237uw1UFzeZDs9r//vrzDyXsIblAzshqihy	\N	4	2026-02-11 13:10:35	2026-02-11 15:30:00	company_admin	t	\N	\N	\N	pos_admin
2	Company Admin	company_admin@test.com	\N	$2y$12$5Z3InHAmCtHb9Bzxq4gCMuRUugiThJGxr/mAWSxzqizwTceOnk2D2	\N	1	2026-02-11 10:22:40	2026-02-11 15:30:01	company_admin	t	\N	\N	\N	pos_admin
4	Demo Company Admin	demo@taxnest.pk	\N	$2y$12$WSxs1d2zjl4KuH8.GMD0PuWDCH0cnYmqItAeDWgRZ.qALSBMBTG1i	kJTurRJTVVx6ifEiAYtBKFRBMTPR3tQJkIWBzFwno54FJMieHSOrTSuPtZGN	2	2026-02-11 11:46:17	2026-02-12 06:18:33	company_admin	t	f	\N	\N	pos_admin
8	Test Admin	testadmin@sandbox.com	\N	$2y$12$fWl.iiWQFJX9LyH0Bv81sOGJt7CFek.ZBleHRL.DOuLRbwO.9Yfse	\N	5	2026-02-13 05:03:24	2026-02-13 05:03:24	company_admin	t	\N	\N	\N	pos_admin
\.


--
-- Data for Name: vendor_risk_profiles; Type: TABLE DATA; Schema: public; Owner: postgres
--

COPY public.vendor_risk_profiles (id, company_id, vendor_ntn, vendor_name, vendor_score, total_invoices, rejected_invoices, tax_mismatches, anomaly_count, last_flagged_at, created_at, updated_at) FROM stdin;
1	2	1234567-8	Test Buyer	100	1	0	0	0	\N	2026-02-11 12:24:16	2026-02-11 12:24:16
\.


--
-- Name: admin_announcements_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.admin_announcements_id_seq', 1, false);


--
-- Name: admin_audit_logs_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.admin_audit_logs_id_seq', 7, true);


--
-- Name: admin_users_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.admin_users_id_seq', 1, true);


--
-- Name: announcement_dismissals_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.announcement_dismissals_id_seq', 1, false);


--
-- Name: anomaly_logs_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.anomaly_logs_id_seq', 3, true);


--
-- Name: audit_logs_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.audit_logs_id_seq', 159, true);


--
-- Name: branches_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.branches_id_seq', 1, false);


--
-- Name: companies_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.companies_id_seq', 14, true);


--
-- Name: company_usage_stats_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.company_usage_stats_id_seq', 8, true);


--
-- Name: compliance_reports_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.compliance_reports_id_seq', 92, true);


--
-- Name: compliance_scores_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.compliance_scores_id_seq', 1, false);


--
-- Name: customer_ledgers_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.customer_ledgers_id_seq', 21, true);


--
-- Name: customer_profiles_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.customer_profiles_id_seq', 2, true);


--
-- Name: customer_tax_rules_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.customer_tax_rules_id_seq', 1, false);


--
-- Name: failed_jobs_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.failed_jobs_id_seq', 4, true);


--
-- Name: fbr_logs_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.fbr_logs_id_seq', 143, true);


--
-- Name: franchises_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.franchises_id_seq', 1, false);


--
-- Name: global_hs_master_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.global_hs_master_id_seq', 16, true);


--
-- Name: hs_code_mappings_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.hs_code_mappings_id_seq', 1, false);


--
-- Name: hs_intelligence_logs_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.hs_intelligence_logs_id_seq', 3, true);


--
-- Name: hs_mapping_audit_logs_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.hs_mapping_audit_logs_id_seq', 1, false);


--
-- Name: hs_mapping_responses_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.hs_mapping_responses_id_seq', 1, false);


--
-- Name: hs_master_global_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.hs_master_global_id_seq', 612, true);


--
-- Name: hs_rejection_history_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.hs_rejection_history_id_seq', 5, true);


--
-- Name: hs_unmapped_log_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.hs_unmapped_log_id_seq', 8, true);


--
-- Name: hs_unmapped_queue_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.hs_unmapped_queue_id_seq', 4, true);


--
-- Name: hs_usage_patterns_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.hs_usage_patterns_id_seq', 2, true);


--
-- Name: inventory_adjustments_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.inventory_adjustments_id_seq', 1, false);


--
-- Name: inventory_movements_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.inventory_movements_id_seq', 1, true);


--
-- Name: inventory_stocks_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.inventory_stocks_id_seq', 3, true);


--
-- Name: invoice_activity_logs_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.invoice_activity_logs_id_seq', 214, true);


--
-- Name: invoice_items_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.invoice_items_id_seq', 45, true);


--
-- Name: invoices_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.invoices_id_seq', 30, true);


--
-- Name: jobs_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.jobs_id_seq', 134, true);


--
-- Name: migrations_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.migrations_id_seq', 81, true);


--
-- Name: notifications_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.notifications_id_seq', 1, false);


--
-- Name: override_logs_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.override_logs_id_seq', 4, true);


--
-- Name: override_usage_logs_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.override_usage_logs_id_seq', 1, false);


--
-- Name: pos_customers_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.pos_customers_id_seq', 8, true);


--
-- Name: pos_payments_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.pos_payments_id_seq', 21, true);


--
-- Name: pos_products_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.pos_products_id_seq', 29, true);


--
-- Name: pos_services_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.pos_services_id_seq', 1, false);


--
-- Name: pos_tax_rules_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.pos_tax_rules_id_seq', 4, true);


--
-- Name: pos_terminals_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.pos_terminals_id_seq', 1, true);


--
-- Name: pos_transaction_items_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.pos_transaction_items_id_seq', 195, true);


--
-- Name: pos_transactions_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.pos_transactions_id_seq', 34, true);


--
-- Name: pra_logs_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.pra_logs_id_seq', 40, true);


--
-- Name: pricing_plans_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.pricing_plans_id_seq', 11, true);


--
-- Name: products_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.products_id_seq', 7, true);


--
-- Name: province_tax_rules_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.province_tax_rules_id_seq', 1, false);


--
-- Name: purchase_order_items_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.purchase_order_items_id_seq', 1, false);


--
-- Name: purchase_orders_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.purchase_orders_id_seq', 1, false);


--
-- Name: sector_tax_rules_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.sector_tax_rules_id_seq', 1, false);


--
-- Name: security_logs_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.security_logs_id_seq', 493, true);


--
-- Name: special_sro_rules_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.special_sro_rules_id_seq', 64, true);


--
-- Name: subscription_invoices_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.subscription_invoices_id_seq', 1, false);


--
-- Name: subscription_payments_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.subscription_payments_id_seq', 1, false);


--
-- Name: subscriptions_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.subscriptions_id_seq', 11, true);


--
-- Name: suppliers_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.suppliers_id_seq', 1, false);


--
-- Name: system_controls_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.system_controls_id_seq', 4, true);


--
-- Name: system_settings_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.system_settings_id_seq', 5, true);


--
-- Name: users_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.users_id_seq', 15, true);


--
-- Name: vendor_risk_profiles_id_seq; Type: SEQUENCE SET; Schema: public; Owner: postgres
--

SELECT pg_catalog.setval('public.vendor_risk_profiles_id_seq', 1, true);


--
-- Name: admin_announcements admin_announcements_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.admin_announcements
    ADD CONSTRAINT admin_announcements_pkey PRIMARY KEY (id);


--
-- Name: admin_audit_logs admin_audit_logs_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.admin_audit_logs
    ADD CONSTRAINT admin_audit_logs_pkey PRIMARY KEY (id);


--
-- Name: admin_users admin_users_email_unique; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.admin_users
    ADD CONSTRAINT admin_users_email_unique UNIQUE (email);


--
-- Name: admin_users admin_users_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.admin_users
    ADD CONSTRAINT admin_users_pkey PRIMARY KEY (id);


--
-- Name: announcement_dismissals announcement_dismissals_announcement_id_user_id_unique; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.announcement_dismissals
    ADD CONSTRAINT announcement_dismissals_announcement_id_user_id_unique UNIQUE (announcement_id, user_id);


--
-- Name: announcement_dismissals announcement_dismissals_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.announcement_dismissals
    ADD CONSTRAINT announcement_dismissals_pkey PRIMARY KEY (id);


--
-- Name: anomaly_logs anomaly_logs_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.anomaly_logs
    ADD CONSTRAINT anomaly_logs_pkey PRIMARY KEY (id);


--
-- Name: audit_logs audit_logs_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.audit_logs
    ADD CONSTRAINT audit_logs_pkey PRIMARY KEY (id);


--
-- Name: branches branches_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.branches
    ADD CONSTRAINT branches_pkey PRIMARY KEY (id);


--
-- Name: cache_locks cache_locks_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.cache_locks
    ADD CONSTRAINT cache_locks_pkey PRIMARY KEY (key);


--
-- Name: cache cache_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.cache
    ADD CONSTRAINT cache_pkey PRIMARY KEY (key);


--
-- Name: companies companies_ntn_unique; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.companies
    ADD CONSTRAINT companies_ntn_unique UNIQUE (ntn);


--
-- Name: companies companies_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.companies
    ADD CONSTRAINT companies_pkey PRIMARY KEY (id);


--
-- Name: company_usage_stats company_usage_stats_company_id_unique; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.company_usage_stats
    ADD CONSTRAINT company_usage_stats_company_id_unique UNIQUE (company_id);


--
-- Name: company_usage_stats company_usage_stats_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.company_usage_stats
    ADD CONSTRAINT company_usage_stats_pkey PRIMARY KEY (id);


--
-- Name: compliance_reports compliance_reports_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.compliance_reports
    ADD CONSTRAINT compliance_reports_pkey PRIMARY KEY (id);


--
-- Name: compliance_scores compliance_scores_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.compliance_scores
    ADD CONSTRAINT compliance_scores_pkey PRIMARY KEY (id);


--
-- Name: customer_ledgers customer_ledgers_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.customer_ledgers
    ADD CONSTRAINT customer_ledgers_pkey PRIMARY KEY (id);


--
-- Name: customer_profiles customer_profiles_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.customer_profiles
    ADD CONSTRAINT customer_profiles_pkey PRIMARY KEY (id);


--
-- Name: customer_tax_rules customer_tax_rules_company_id_customer_ntn_hs_code_unique; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.customer_tax_rules
    ADD CONSTRAINT customer_tax_rules_company_id_customer_ntn_hs_code_unique UNIQUE (company_id, customer_ntn, hs_code);


--
-- Name: customer_tax_rules customer_tax_rules_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.customer_tax_rules
    ADD CONSTRAINT customer_tax_rules_pkey PRIMARY KEY (id);


--
-- Name: failed_jobs failed_jobs_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.failed_jobs
    ADD CONSTRAINT failed_jobs_pkey PRIMARY KEY (id);


--
-- Name: failed_jobs failed_jobs_uuid_unique; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.failed_jobs
    ADD CONSTRAINT failed_jobs_uuid_unique UNIQUE (uuid);


--
-- Name: fbr_logs fbr_logs_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.fbr_logs
    ADD CONSTRAINT fbr_logs_pkey PRIMARY KEY (id);


--
-- Name: franchises franchises_email_unique; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.franchises
    ADD CONSTRAINT franchises_email_unique UNIQUE (email);


--
-- Name: franchises franchises_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.franchises
    ADD CONSTRAINT franchises_pkey PRIMARY KEY (id);


--
-- Name: global_hs_master global_hs_master_hs_code_unique; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.global_hs_master
    ADD CONSTRAINT global_hs_master_hs_code_unique UNIQUE (hs_code);


--
-- Name: global_hs_master global_hs_master_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.global_hs_master
    ADD CONSTRAINT global_hs_master_pkey PRIMARY KEY (id);


--
-- Name: hs_code_mappings hs_code_mappings_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.hs_code_mappings
    ADD CONSTRAINT hs_code_mappings_pkey PRIMARY KEY (id);


--
-- Name: hs_intelligence_logs hs_intelligence_logs_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.hs_intelligence_logs
    ADD CONSTRAINT hs_intelligence_logs_pkey PRIMARY KEY (id);


--
-- Name: hs_mapping_audit_logs hs_mapping_audit_logs_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.hs_mapping_audit_logs
    ADD CONSTRAINT hs_mapping_audit_logs_pkey PRIMARY KEY (id);


--
-- Name: hs_mapping_responses hs_mapping_responses_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.hs_mapping_responses
    ADD CONSTRAINT hs_mapping_responses_pkey PRIMARY KEY (id);


--
-- Name: hs_master_global hs_master_global_hs_code_unique; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.hs_master_global
    ADD CONSTRAINT hs_master_global_hs_code_unique UNIQUE (hs_code);


--
-- Name: hs_master_global hs_master_global_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.hs_master_global
    ADD CONSTRAINT hs_master_global_pkey PRIMARY KEY (id);


--
-- Name: hs_rejection_history hs_rejection_history_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.hs_rejection_history
    ADD CONSTRAINT hs_rejection_history_pkey PRIMARY KEY (id);


--
-- Name: hs_unmapped_log hs_unmapped_log_hs_code_company_id_unique; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.hs_unmapped_log
    ADD CONSTRAINT hs_unmapped_log_hs_code_company_id_unique UNIQUE (hs_code, company_id);


--
-- Name: hs_unmapped_log hs_unmapped_log_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.hs_unmapped_log
    ADD CONSTRAINT hs_unmapped_log_pkey PRIMARY KEY (id);


--
-- Name: hs_unmapped_queue hs_unmapped_queue_hs_code_company_id_unique; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.hs_unmapped_queue
    ADD CONSTRAINT hs_unmapped_queue_hs_code_company_id_unique UNIQUE (hs_code, company_id);


--
-- Name: hs_unmapped_queue hs_unmapped_queue_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.hs_unmapped_queue
    ADD CONSTRAINT hs_unmapped_queue_pkey PRIMARY KEY (id);


--
-- Name: hs_usage_patterns hs_usage_patterns_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.hs_usage_patterns
    ADD CONSTRAINT hs_usage_patterns_pkey PRIMARY KEY (id);


--
-- Name: hs_usage_patterns hs_usage_unique; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.hs_usage_patterns
    ADD CONSTRAINT hs_usage_unique UNIQUE (hs_code, schedule_type, tax_rate);


--
-- Name: inventory_stocks inv_stock_unique; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.inventory_stocks
    ADD CONSTRAINT inv_stock_unique UNIQUE (company_id, product_id, branch_id);


--
-- Name: inventory_adjustments inventory_adjustments_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.inventory_adjustments
    ADD CONSTRAINT inventory_adjustments_pkey PRIMARY KEY (id);


--
-- Name: inventory_movements inventory_movements_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.inventory_movements
    ADD CONSTRAINT inventory_movements_pkey PRIMARY KEY (id);


--
-- Name: inventory_stocks inventory_stocks_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.inventory_stocks
    ADD CONSTRAINT inventory_stocks_pkey PRIMARY KEY (id);


--
-- Name: invoice_activity_logs invoice_activity_logs_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.invoice_activity_logs
    ADD CONSTRAINT invoice_activity_logs_pkey PRIMARY KEY (id);


--
-- Name: invoice_items invoice_items_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.invoice_items
    ADD CONSTRAINT invoice_items_pkey PRIMARY KEY (id);


--
-- Name: invoices invoices_company_internal_number_unique; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.invoices
    ADD CONSTRAINT invoices_company_internal_number_unique UNIQUE (company_id, internal_invoice_number);


--
-- Name: invoices invoices_fbr_invoice_number_unique; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.invoices
    ADD CONSTRAINT invoices_fbr_invoice_number_unique UNIQUE (fbr_invoice_number);


--
-- Name: invoices invoices_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.invoices
    ADD CONSTRAINT invoices_pkey PRIMARY KEY (id);


--
-- Name: invoices invoices_share_uuid_unique; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.invoices
    ADD CONSTRAINT invoices_share_uuid_unique UNIQUE (share_uuid);


--
-- Name: job_batches job_batches_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.job_batches
    ADD CONSTRAINT job_batches_pkey PRIMARY KEY (id);


--
-- Name: jobs jobs_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.jobs
    ADD CONSTRAINT jobs_pkey PRIMARY KEY (id);


--
-- Name: migrations migrations_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.migrations
    ADD CONSTRAINT migrations_pkey PRIMARY KEY (id);


--
-- Name: notifications notifications_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.notifications
    ADD CONSTRAINT notifications_pkey PRIMARY KEY (id);


--
-- Name: override_logs override_logs_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.override_logs
    ADD CONSTRAINT override_logs_pkey PRIMARY KEY (id);


--
-- Name: override_usage_logs override_usage_logs_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.override_usage_logs
    ADD CONSTRAINT override_usage_logs_pkey PRIMARY KEY (id);


--
-- Name: password_reset_tokens password_reset_tokens_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.password_reset_tokens
    ADD CONSTRAINT password_reset_tokens_pkey PRIMARY KEY (email);


--
-- Name: pos_customers pos_customers_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.pos_customers
    ADD CONSTRAINT pos_customers_pkey PRIMARY KEY (id);


--
-- Name: pos_payments pos_payments_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.pos_payments
    ADD CONSTRAINT pos_payments_pkey PRIMARY KEY (id);


--
-- Name: pos_products pos_products_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.pos_products
    ADD CONSTRAINT pos_products_pkey PRIMARY KEY (id);


--
-- Name: pos_services pos_services_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.pos_services
    ADD CONSTRAINT pos_services_pkey PRIMARY KEY (id);


--
-- Name: pos_tax_rules pos_tax_rules_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.pos_tax_rules
    ADD CONSTRAINT pos_tax_rules_pkey PRIMARY KEY (id);


--
-- Name: pos_terminals pos_terminals_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.pos_terminals
    ADD CONSTRAINT pos_terminals_pkey PRIMARY KEY (id);


--
-- Name: pos_terminals pos_terminals_terminal_id_unique; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.pos_terminals
    ADD CONSTRAINT pos_terminals_terminal_id_unique UNIQUE (terminal_code);


--
-- Name: pos_transaction_items pos_transaction_items_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.pos_transaction_items
    ADD CONSTRAINT pos_transaction_items_pkey PRIMARY KEY (id);


--
-- Name: pos_transactions pos_transactions_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.pos_transactions
    ADD CONSTRAINT pos_transactions_pkey PRIMARY KEY (id);


--
-- Name: pos_transactions pos_transactions_share_token_unique; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.pos_transactions
    ADD CONSTRAINT pos_transactions_share_token_unique UNIQUE (share_token);


--
-- Name: pra_logs pra_logs_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.pra_logs
    ADD CONSTRAINT pra_logs_pkey PRIMARY KEY (id);


--
-- Name: pricing_plans pricing_plans_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.pricing_plans
    ADD CONSTRAINT pricing_plans_pkey PRIMARY KEY (id);


--
-- Name: products products_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.products
    ADD CONSTRAINT products_pkey PRIMARY KEY (id);


--
-- Name: province_tax_rules province_tax_rules_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.province_tax_rules
    ADD CONSTRAINT province_tax_rules_pkey PRIMARY KEY (id);


--
-- Name: province_tax_rules province_tax_rules_province_hs_code_unique; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.province_tax_rules
    ADD CONSTRAINT province_tax_rules_province_hs_code_unique UNIQUE (province, hs_code);


--
-- Name: purchase_order_items purchase_order_items_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.purchase_order_items
    ADD CONSTRAINT purchase_order_items_pkey PRIMARY KEY (id);


--
-- Name: purchase_orders purchase_orders_company_id_po_number_unique; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.purchase_orders
    ADD CONSTRAINT purchase_orders_company_id_po_number_unique UNIQUE (company_id, po_number);


--
-- Name: purchase_orders purchase_orders_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.purchase_orders
    ADD CONSTRAINT purchase_orders_pkey PRIMARY KEY (id);


--
-- Name: sector_tax_rules sector_tax_rules_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.sector_tax_rules
    ADD CONSTRAINT sector_tax_rules_pkey PRIMARY KEY (id);


--
-- Name: sector_tax_rules sector_tax_rules_sector_type_hs_code_unique; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.sector_tax_rules
    ADD CONSTRAINT sector_tax_rules_sector_type_hs_code_unique UNIQUE (sector_type, hs_code);


--
-- Name: security_logs security_logs_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.security_logs
    ADD CONSTRAINT security_logs_pkey PRIMARY KEY (id);


--
-- Name: sessions sessions_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.sessions
    ADD CONSTRAINT sessions_pkey PRIMARY KEY (id);


--
-- Name: special_sro_rules special_sro_rules_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.special_sro_rules
    ADD CONSTRAINT special_sro_rules_pkey PRIMARY KEY (id);


--
-- Name: subscription_invoices subscription_invoices_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.subscription_invoices
    ADD CONSTRAINT subscription_invoices_pkey PRIMARY KEY (id);


--
-- Name: subscription_payments subscription_payments_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.subscription_payments
    ADD CONSTRAINT subscription_payments_pkey PRIMARY KEY (id);


--
-- Name: subscriptions subscriptions_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.subscriptions
    ADD CONSTRAINT subscriptions_pkey PRIMARY KEY (id);


--
-- Name: suppliers suppliers_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.suppliers
    ADD CONSTRAINT suppliers_pkey PRIMARY KEY (id);


--
-- Name: system_controls system_controls_key_unique; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.system_controls
    ADD CONSTRAINT system_controls_key_unique UNIQUE (key);


--
-- Name: system_controls system_controls_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.system_controls
    ADD CONSTRAINT system_controls_pkey PRIMARY KEY (id);


--
-- Name: system_settings system_settings_key_unique; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.system_settings
    ADD CONSTRAINT system_settings_key_unique UNIQUE (key);


--
-- Name: system_settings system_settings_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.system_settings
    ADD CONSTRAINT system_settings_pkey PRIMARY KEY (id);


--
-- Name: users users_email_unique; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_email_unique UNIQUE (email);


--
-- Name: users users_phone_unique; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_phone_unique UNIQUE (phone);


--
-- Name: users users_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_pkey PRIMARY KEY (id);


--
-- Name: users users_username_unique; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_username_unique UNIQUE (username);


--
-- Name: vendor_risk_profiles vendor_risk_profiles_company_id_vendor_ntn_unique; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.vendor_risk_profiles
    ADD CONSTRAINT vendor_risk_profiles_company_id_vendor_ntn_unique UNIQUE (company_id, vendor_ntn);


--
-- Name: vendor_risk_profiles vendor_risk_profiles_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.vendor_risk_profiles
    ADD CONSTRAINT vendor_risk_profiles_pkey PRIMARY KEY (id);


--
-- Name: admin_announcements_is_active_expires_at_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX admin_announcements_is_active_expires_at_index ON public.admin_announcements USING btree (is_active, expires_at);


--
-- Name: admin_audit_logs_admin_id_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX admin_audit_logs_admin_id_index ON public.admin_audit_logs USING btree (admin_id);


--
-- Name: admin_audit_logs_target_type_target_id_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX admin_audit_logs_target_type_target_id_index ON public.admin_audit_logs USING btree (target_type, target_id);


--
-- Name: anomaly_logs_company_id_type_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX anomaly_logs_company_id_type_index ON public.anomaly_logs USING btree (company_id, type);


--
-- Name: anomaly_logs_resolved_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX anomaly_logs_resolved_index ON public.anomaly_logs USING btree (resolved);


--
-- Name: audit_logs_company_id_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX audit_logs_company_id_index ON public.audit_logs USING btree (company_id);


--
-- Name: audit_logs_created_at_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX audit_logs_created_at_index ON public.audit_logs USING btree (created_at);


--
-- Name: cache_expiration_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX cache_expiration_index ON public.cache USING btree (expiration);


--
-- Name: cache_locks_expiration_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX cache_locks_expiration_index ON public.cache_locks USING btree (expiration);


--
-- Name: companies_company_status_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX companies_company_status_index ON public.companies USING btree (company_status);


--
-- Name: companies_compliance_score_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX companies_compliance_score_index ON public.companies USING btree (compliance_score);


--
-- Name: companies_name_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX companies_name_index ON public.companies USING btree (name);


--
-- Name: compliance_reports_company_id_created_at_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX compliance_reports_company_id_created_at_index ON public.compliance_reports USING btree (company_id, created_at);


--
-- Name: compliance_reports_risk_level_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX compliance_reports_risk_level_index ON public.compliance_reports USING btree (risk_level);


--
-- Name: compliance_scores_company_id_calculated_date_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX compliance_scores_company_id_calculated_date_index ON public.compliance_scores USING btree (company_id, calculated_date);


--
-- Name: customer_profiles_company_ntn_not_null; Type: INDEX; Schema: public; Owner: postgres
--

CREATE UNIQUE INDEX customer_profiles_company_ntn_not_null ON public.customer_profiles USING btree (company_id, ntn) WHERE (ntn IS NOT NULL);


--
-- Name: customer_tax_rules_company_id_customer_ntn_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX customer_tax_rules_company_id_customer_ntn_index ON public.customer_tax_rules USING btree (company_id, customer_ntn);


--
-- Name: fbr_logs_invoice_id_status_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX fbr_logs_invoice_id_status_index ON public.fbr_logs USING btree (invoice_id, status);


--
-- Name: fbr_logs_request_payload_hash_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX fbr_logs_request_payload_hash_index ON public.fbr_logs USING btree (request_payload_hash);


--
-- Name: fbr_logs_status_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX fbr_logs_status_index ON public.fbr_logs USING btree (status);


--
-- Name: global_hs_master_mapping_status_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX global_hs_master_mapping_status_index ON public.global_hs_master USING btree (mapping_status);


--
-- Name: global_hs_master_schedule_type_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX global_hs_master_schedule_type_index ON public.global_hs_master USING btree (schedule_type);


--
-- Name: global_hs_master_sector_tag_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX global_hs_master_sector_tag_index ON public.global_hs_master USING btree (sector_tag);


--
-- Name: global_hs_master_tax_rate_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX global_hs_master_tax_rate_index ON public.global_hs_master USING btree (tax_rate);


--
-- Name: hs_code_mappings_hs_code_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX hs_code_mappings_hs_code_index ON public.hs_code_mappings USING btree (hs_code);


--
-- Name: hs_code_mappings_hs_code_is_active_priority_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX hs_code_mappings_hs_code_is_active_priority_index ON public.hs_code_mappings USING btree (hs_code, is_active, priority);


--
-- Name: hs_intelligence_logs_confidence_score_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX hs_intelligence_logs_confidence_score_index ON public.hs_intelligence_logs USING btree (confidence_score);


--
-- Name: hs_intelligence_logs_hs_code_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX hs_intelligence_logs_hs_code_index ON public.hs_intelligence_logs USING btree (hs_code);


--
-- Name: hs_mapping_audit_logs_changed_by_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX hs_mapping_audit_logs_changed_by_index ON public.hs_mapping_audit_logs USING btree (changed_by);


--
-- Name: hs_mapping_audit_logs_created_at_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX hs_mapping_audit_logs_created_at_index ON public.hs_mapping_audit_logs USING btree (created_at);


--
-- Name: hs_mapping_audit_logs_hs_code_mapping_id_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX hs_mapping_audit_logs_hs_code_mapping_id_index ON public.hs_mapping_audit_logs USING btree (hs_code_mapping_id);


--
-- Name: hs_mapping_responses_company_id_hs_code_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX hs_mapping_responses_company_id_hs_code_index ON public.hs_mapping_responses USING btree (company_id, hs_code);


--
-- Name: hs_mapping_responses_hs_code_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX hs_mapping_responses_hs_code_index ON public.hs_mapping_responses USING btree (hs_code);


--
-- Name: hs_master_global_default_tax_rate_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX hs_master_global_default_tax_rate_index ON public.hs_master_global USING btree (default_tax_rate);


--
-- Name: hs_master_global_hs_code_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX hs_master_global_hs_code_index ON public.hs_master_global USING btree (hs_code);


--
-- Name: hs_master_global_is_active_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX hs_master_global_is_active_index ON public.hs_master_global USING btree (is_active);


--
-- Name: hs_master_global_schedule_type_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX hs_master_global_schedule_type_index ON public.hs_master_global USING btree (schedule_type);


--
-- Name: hs_rejection_history_hs_code_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX hs_rejection_history_hs_code_index ON public.hs_rejection_history USING btree (hs_code);


--
-- Name: hs_unmapped_log_company_id_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX hs_unmapped_log_company_id_index ON public.hs_unmapped_log USING btree (company_id);


--
-- Name: hs_unmapped_log_frequency_count_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX hs_unmapped_log_frequency_count_index ON public.hs_unmapped_log USING btree (frequency_count);


--
-- Name: hs_unmapped_log_hs_code_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX hs_unmapped_log_hs_code_index ON public.hs_unmapped_log USING btree (hs_code);


--
-- Name: hs_unmapped_log_last_seen_at_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX hs_unmapped_log_last_seen_at_index ON public.hs_unmapped_log USING btree (last_seen_at);


--
-- Name: hs_unmapped_queue_hs_code_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX hs_unmapped_queue_hs_code_index ON public.hs_unmapped_queue USING btree (hs_code);


--
-- Name: hs_usage_patterns_hs_code_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX hs_usage_patterns_hs_code_index ON public.hs_usage_patterns USING btree (hs_code);


--
-- Name: inventory_adjustments_company_id_product_id_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX inventory_adjustments_company_id_product_id_index ON public.inventory_adjustments USING btree (company_id, product_id);


--
-- Name: inventory_movements_company_id_product_id_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX inventory_movements_company_id_product_id_index ON public.inventory_movements USING btree (company_id, product_id);


--
-- Name: inventory_movements_company_id_type_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX inventory_movements_company_id_type_index ON public.inventory_movements USING btree (company_id, type);


--
-- Name: inventory_movements_reference_type_reference_id_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX inventory_movements_reference_type_reference_id_index ON public.inventory_movements USING btree (reference_type, reference_id);


--
-- Name: inventory_stocks_company_id_product_id_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX inventory_stocks_company_id_product_id_index ON public.inventory_stocks USING btree (company_id, product_id);


--
-- Name: invoice_items_hs_code_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX invoice_items_hs_code_index ON public.invoice_items USING btree (hs_code);


--
-- Name: invoice_items_hs_code_invoice_id_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX invoice_items_hs_code_invoice_id_index ON public.invoice_items USING btree (hs_code, invoice_id);


--
-- Name: invoice_items_invoice_id_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX invoice_items_invoice_id_index ON public.invoice_items USING btree (invoice_id);


--
-- Name: invoices_company_id_created_at_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX invoices_company_id_created_at_index ON public.invoices USING btree (company_id, created_at);


--
-- Name: invoices_company_id_status_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX invoices_company_id_status_index ON public.invoices USING btree (company_id, status);


--
-- Name: invoices_company_status_date_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX invoices_company_status_date_index ON public.invoices USING btree (company_id, status, invoice_date);


--
-- Name: invoices_created_at_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX invoices_created_at_index ON public.invoices USING btree (created_at);


--
-- Name: invoices_fbr_submission_hash_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX invoices_fbr_submission_hash_index ON public.invoices USING btree (fbr_submission_hash);


--
-- Name: invoices_invoice_number_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX invoices_invoice_number_index ON public.invoices USING btree (invoice_number);


--
-- Name: invoices_status_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX invoices_status_index ON public.invoices USING btree (status);


--
-- Name: jobs_queue_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX jobs_queue_index ON public.jobs USING btree (queue);


--
-- Name: override_usage_logs_company_id_override_layer_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX override_usage_logs_company_id_override_layer_index ON public.override_usage_logs USING btree (company_id, override_layer);


--
-- Name: override_usage_logs_created_at_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX override_usage_logs_created_at_index ON public.override_usage_logs USING btree (created_at);


--
-- Name: pos_customers_company_id_is_active_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX pos_customers_company_id_is_active_index ON public.pos_customers USING btree (company_id, is_active);


--
-- Name: pos_payments_transaction_id_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX pos_payments_transaction_id_index ON public.pos_payments USING btree (transaction_id);


--
-- Name: pos_products_company_id_is_active_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX pos_products_company_id_is_active_index ON public.pos_products USING btree (company_id, is_active);


--
-- Name: pos_services_company_id_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX pos_services_company_id_index ON public.pos_services USING btree (company_id);


--
-- Name: pos_terminals_company_id_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX pos_terminals_company_id_index ON public.pos_terminals USING btree (company_id);


--
-- Name: pos_transaction_items_transaction_id_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX pos_transaction_items_transaction_id_index ON public.pos_transaction_items USING btree (transaction_id);


--
-- Name: pos_transactions_company_id_created_at_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX pos_transactions_company_id_created_at_index ON public.pos_transactions USING btree (company_id, created_at);


--
-- Name: pos_transactions_company_id_payment_method_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX pos_transactions_company_id_payment_method_index ON public.pos_transactions USING btree (company_id, payment_method);


--
-- Name: pos_transactions_company_id_status_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX pos_transactions_company_id_status_index ON public.pos_transactions USING btree (company_id, status);


--
-- Name: pos_transactions_company_invoice_unique; Type: INDEX; Schema: public; Owner: postgres
--

CREATE UNIQUE INDEX pos_transactions_company_invoice_unique ON public.pos_transactions USING btree (company_id, invoice_number);


--
-- Name: pos_transactions_locked_by_terminal_id_lock_time_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX pos_transactions_locked_by_terminal_id_lock_time_index ON public.pos_transactions USING btree (locked_by_terminal_id, lock_time);


--
-- Name: pos_transactions_submission_hash_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX pos_transactions_submission_hash_index ON public.pos_transactions USING btree (submission_hash);


--
-- Name: pra_logs_company_id_created_at_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX pra_logs_company_id_created_at_index ON public.pra_logs USING btree (company_id, created_at);


--
-- Name: province_tax_rules_province_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX province_tax_rules_province_index ON public.province_tax_rules USING btree (province);


--
-- Name: purchase_orders_company_id_status_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX purchase_orders_company_id_status_index ON public.purchase_orders USING btree (company_id, status);


--
-- Name: sector_tax_rules_sector_type_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX sector_tax_rules_sector_type_index ON public.sector_tax_rules USING btree (sector_type);


--
-- Name: sessions_last_activity_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX sessions_last_activity_index ON public.sessions USING btree (last_activity);


--
-- Name: sessions_user_id_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX sessions_user_id_index ON public.sessions USING btree (user_id);


--
-- Name: special_sro_rules_hs_code_schedule_type_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX special_sro_rules_hs_code_schedule_type_index ON public.special_sro_rules USING btree (hs_code, schedule_type);


--
-- Name: subscription_invoices_company_id_status_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX subscription_invoices_company_id_status_index ON public.subscription_invoices USING btree (company_id, status);


--
-- Name: suppliers_company_id_is_active_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX suppliers_company_id_is_active_index ON public.suppliers USING btree (company_id, is_active);


--
-- Name: users_role_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX users_role_index ON public.users USING btree (role);


--
-- Name: vendor_risk_profiles_vendor_score_index; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX vendor_risk_profiles_vendor_score_index ON public.vendor_risk_profiles USING btree (vendor_score);


--
-- Name: users 1; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT "1" FOREIGN KEY (company_id) REFERENCES public.companies(id) ON DELETE CASCADE;


--
-- Name: invoices 1; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.invoices
    ADD CONSTRAINT "1" FOREIGN KEY (company_id) REFERENCES public.companies(id) ON DELETE CASCADE;


--
-- Name: fbr_logs 1; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.fbr_logs
    ADD CONSTRAINT "1" FOREIGN KEY (invoice_id) REFERENCES public.invoices(id) ON DELETE CASCADE;


--
-- Name: admin_announcements admin_announcements_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.admin_announcements
    ADD CONSTRAINT admin_announcements_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: admin_announcements admin_announcements_target_company_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.admin_announcements
    ADD CONSTRAINT admin_announcements_target_company_id_foreign FOREIGN KEY (target_company_id) REFERENCES public.companies(id) ON DELETE CASCADE;


--
-- Name: announcement_dismissals announcement_dismissals_announcement_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.announcement_dismissals
    ADD CONSTRAINT announcement_dismissals_announcement_id_foreign FOREIGN KEY (announcement_id) REFERENCES public.admin_announcements(id) ON DELETE CASCADE;


--
-- Name: announcement_dismissals announcement_dismissals_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.announcement_dismissals
    ADD CONSTRAINT announcement_dismissals_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE CASCADE;


--
-- Name: anomaly_logs anomaly_logs_company_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.anomaly_logs
    ADD CONSTRAINT anomaly_logs_company_id_foreign FOREIGN KEY (company_id) REFERENCES public.companies(id) ON DELETE CASCADE;


--
-- Name: branches branches_company_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.branches
    ADD CONSTRAINT branches_company_id_foreign FOREIGN KEY (company_id) REFERENCES public.companies(id) ON DELETE CASCADE;


--
-- Name: companies companies_franchise_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.companies
    ADD CONSTRAINT companies_franchise_id_foreign FOREIGN KEY (franchise_id) REFERENCES public.franchises(id) ON DELETE SET NULL;


--
-- Name: company_usage_stats company_usage_stats_company_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.company_usage_stats
    ADD CONSTRAINT company_usage_stats_company_id_foreign FOREIGN KEY (company_id) REFERENCES public.companies(id) ON DELETE CASCADE;


--
-- Name: compliance_reports compliance_reports_company_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.compliance_reports
    ADD CONSTRAINT compliance_reports_company_id_foreign FOREIGN KEY (company_id) REFERENCES public.companies(id) ON DELETE CASCADE;


--
-- Name: compliance_reports compliance_reports_invoice_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.compliance_reports
    ADD CONSTRAINT compliance_reports_invoice_id_foreign FOREIGN KEY (invoice_id) REFERENCES public.invoices(id) ON DELETE SET NULL;


--
-- Name: compliance_scores compliance_scores_company_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.compliance_scores
    ADD CONSTRAINT compliance_scores_company_id_foreign FOREIGN KEY (company_id) REFERENCES public.companies(id) ON DELETE CASCADE;


--
-- Name: customer_ledgers customer_ledgers_company_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.customer_ledgers
    ADD CONSTRAINT customer_ledgers_company_id_foreign FOREIGN KEY (company_id) REFERENCES public.companies(id) ON DELETE CASCADE;


--
-- Name: customer_ledgers customer_ledgers_invoice_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.customer_ledgers
    ADD CONSTRAINT customer_ledgers_invoice_id_foreign FOREIGN KEY (invoice_id) REFERENCES public.invoices(id) ON DELETE SET NULL;


--
-- Name: customer_profiles customer_profiles_company_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.customer_profiles
    ADD CONSTRAINT customer_profiles_company_id_foreign FOREIGN KEY (company_id) REFERENCES public.companies(id) ON DELETE CASCADE;


--
-- Name: customer_tax_rules customer_tax_rules_company_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.customer_tax_rules
    ADD CONSTRAINT customer_tax_rules_company_id_foreign FOREIGN KEY (company_id) REFERENCES public.companies(id) ON DELETE CASCADE;


--
-- Name: hs_mapping_responses hs_mapping_responses_hs_code_mapping_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.hs_mapping_responses
    ADD CONSTRAINT hs_mapping_responses_hs_code_mapping_id_foreign FOREIGN KEY (hs_code_mapping_id) REFERENCES public.hs_code_mappings(id) ON DELETE CASCADE;


--
-- Name: hs_unmapped_queue hs_unmapped_queue_company_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.hs_unmapped_queue
    ADD CONSTRAINT hs_unmapped_queue_company_id_foreign FOREIGN KEY (company_id) REFERENCES public.companies(id) ON DELETE CASCADE;


--
-- Name: inventory_adjustments inventory_adjustments_company_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.inventory_adjustments
    ADD CONSTRAINT inventory_adjustments_company_id_foreign FOREIGN KEY (company_id) REFERENCES public.companies(id) ON DELETE CASCADE;


--
-- Name: inventory_adjustments inventory_adjustments_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.inventory_adjustments
    ADD CONSTRAINT inventory_adjustments_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: inventory_adjustments inventory_adjustments_product_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.inventory_adjustments
    ADD CONSTRAINT inventory_adjustments_product_id_foreign FOREIGN KEY (product_id) REFERENCES public.products(id) ON DELETE CASCADE;


--
-- Name: inventory_movements inventory_movements_branch_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.inventory_movements
    ADD CONSTRAINT inventory_movements_branch_id_foreign FOREIGN KEY (branch_id) REFERENCES public.branches(id) ON DELETE SET NULL;


--
-- Name: inventory_movements inventory_movements_company_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.inventory_movements
    ADD CONSTRAINT inventory_movements_company_id_foreign FOREIGN KEY (company_id) REFERENCES public.companies(id) ON DELETE CASCADE;


--
-- Name: inventory_movements inventory_movements_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.inventory_movements
    ADD CONSTRAINT inventory_movements_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: inventory_movements inventory_movements_product_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.inventory_movements
    ADD CONSTRAINT inventory_movements_product_id_foreign FOREIGN KEY (product_id) REFERENCES public.products(id) ON DELETE CASCADE;


--
-- Name: inventory_stocks inventory_stocks_branch_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.inventory_stocks
    ADD CONSTRAINT inventory_stocks_branch_id_foreign FOREIGN KEY (branch_id) REFERENCES public.branches(id) ON DELETE SET NULL;


--
-- Name: inventory_stocks inventory_stocks_company_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.inventory_stocks
    ADD CONSTRAINT inventory_stocks_company_id_foreign FOREIGN KEY (company_id) REFERENCES public.companies(id) ON DELETE CASCADE;


--
-- Name: invoice_activity_logs invoice_activity_logs_company_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.invoice_activity_logs
    ADD CONSTRAINT invoice_activity_logs_company_id_foreign FOREIGN KEY (company_id) REFERENCES public.companies(id) ON DELETE CASCADE;


--
-- Name: invoice_activity_logs invoice_activity_logs_invoice_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.invoice_activity_logs
    ADD CONSTRAINT invoice_activity_logs_invoice_id_foreign FOREIGN KEY (invoice_id) REFERENCES public.invoices(id) ON DELETE CASCADE;


--
-- Name: invoice_activity_logs invoice_activity_logs_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.invoice_activity_logs
    ADD CONSTRAINT invoice_activity_logs_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: invoice_items invoice_items_invoice_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.invoice_items
    ADD CONSTRAINT invoice_items_invoice_id_foreign FOREIGN KEY (invoice_id) REFERENCES public.invoices(id) ON DELETE CASCADE;


--
-- Name: invoices invoices_branch_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.invoices
    ADD CONSTRAINT invoices_branch_id_foreign FOREIGN KEY (branch_id) REFERENCES public.branches(id) ON DELETE SET NULL;


--
-- Name: invoices invoices_override_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.invoices
    ADD CONSTRAINT invoices_override_by_foreign FOREIGN KEY (override_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: notifications notifications_company_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.notifications
    ADD CONSTRAINT notifications_company_id_foreign FOREIGN KEY (company_id) REFERENCES public.companies(id) ON DELETE CASCADE;


--
-- Name: notifications notifications_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.notifications
    ADD CONSTRAINT notifications_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: override_logs override_logs_company_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.override_logs
    ADD CONSTRAINT override_logs_company_id_foreign FOREIGN KEY (company_id) REFERENCES public.companies(id);


--
-- Name: override_logs override_logs_invoice_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.override_logs
    ADD CONSTRAINT override_logs_invoice_id_foreign FOREIGN KEY (invoice_id) REFERENCES public.invoices(id);


--
-- Name: override_logs override_logs_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.override_logs
    ADD CONSTRAINT override_logs_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id);


--
-- Name: override_usage_logs override_usage_logs_company_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.override_usage_logs
    ADD CONSTRAINT override_usage_logs_company_id_foreign FOREIGN KEY (company_id) REFERENCES public.companies(id) ON DELETE CASCADE;


--
-- Name: override_usage_logs override_usage_logs_invoice_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.override_usage_logs
    ADD CONSTRAINT override_usage_logs_invoice_id_foreign FOREIGN KEY (invoice_id) REFERENCES public.invoices(id) ON DELETE SET NULL;


--
-- Name: pos_customers pos_customers_company_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.pos_customers
    ADD CONSTRAINT pos_customers_company_id_foreign FOREIGN KEY (company_id) REFERENCES public.companies(id) ON DELETE CASCADE;


--
-- Name: pos_payments pos_payments_transaction_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.pos_payments
    ADD CONSTRAINT pos_payments_transaction_id_foreign FOREIGN KEY (transaction_id) REFERENCES public.pos_transactions(id) ON DELETE CASCADE;


--
-- Name: pos_products pos_products_company_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.pos_products
    ADD CONSTRAINT pos_products_company_id_foreign FOREIGN KEY (company_id) REFERENCES public.companies(id) ON DELETE CASCADE;


--
-- Name: pos_services pos_services_company_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.pos_services
    ADD CONSTRAINT pos_services_company_id_foreign FOREIGN KEY (company_id) REFERENCES public.companies(id) ON DELETE CASCADE;


--
-- Name: pos_terminals pos_terminals_company_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.pos_terminals
    ADD CONSTRAINT pos_terminals_company_id_foreign FOREIGN KEY (company_id) REFERENCES public.companies(id) ON DELETE CASCADE;


--
-- Name: pos_transaction_items pos_transaction_items_transaction_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.pos_transaction_items
    ADD CONSTRAINT pos_transaction_items_transaction_id_foreign FOREIGN KEY (transaction_id) REFERENCES public.pos_transactions(id) ON DELETE CASCADE;


--
-- Name: pos_transactions pos_transactions_company_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.pos_transactions
    ADD CONSTRAINT pos_transactions_company_id_foreign FOREIGN KEY (company_id) REFERENCES public.companies(id) ON DELETE CASCADE;


--
-- Name: pos_transactions pos_transactions_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.pos_transactions
    ADD CONSTRAINT pos_transactions_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: pos_transactions pos_transactions_locked_by_terminal_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.pos_transactions
    ADD CONSTRAINT pos_transactions_locked_by_terminal_id_foreign FOREIGN KEY (locked_by_terminal_id) REFERENCES public.pos_terminals(id) ON DELETE SET NULL;


--
-- Name: pos_transactions pos_transactions_terminal_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.pos_transactions
    ADD CONSTRAINT pos_transactions_terminal_id_foreign FOREIGN KEY (terminal_id) REFERENCES public.pos_terminals(id) ON DELETE SET NULL;


--
-- Name: pra_logs pra_logs_company_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.pra_logs
    ADD CONSTRAINT pra_logs_company_id_foreign FOREIGN KEY (company_id) REFERENCES public.companies(id) ON DELETE CASCADE;


--
-- Name: pra_logs pra_logs_transaction_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.pra_logs
    ADD CONSTRAINT pra_logs_transaction_id_foreign FOREIGN KEY (transaction_id) REFERENCES public.pos_transactions(id) ON DELETE SET NULL;


--
-- Name: products products_company_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.products
    ADD CONSTRAINT products_company_id_foreign FOREIGN KEY (company_id) REFERENCES public.companies(id) ON DELETE CASCADE;


--
-- Name: purchase_order_items purchase_order_items_product_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.purchase_order_items
    ADD CONSTRAINT purchase_order_items_product_id_foreign FOREIGN KEY (product_id) REFERENCES public.products(id) ON DELETE CASCADE;


--
-- Name: purchase_order_items purchase_order_items_purchase_order_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.purchase_order_items
    ADD CONSTRAINT purchase_order_items_purchase_order_id_foreign FOREIGN KEY (purchase_order_id) REFERENCES public.purchase_orders(id) ON DELETE CASCADE;


--
-- Name: purchase_orders purchase_orders_branch_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.purchase_orders
    ADD CONSTRAINT purchase_orders_branch_id_foreign FOREIGN KEY (branch_id) REFERENCES public.branches(id) ON DELETE SET NULL;


--
-- Name: purchase_orders purchase_orders_company_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.purchase_orders
    ADD CONSTRAINT purchase_orders_company_id_foreign FOREIGN KEY (company_id) REFERENCES public.companies(id) ON DELETE CASCADE;


--
-- Name: purchase_orders purchase_orders_created_by_foreign; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.purchase_orders
    ADD CONSTRAINT purchase_orders_created_by_foreign FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: purchase_orders purchase_orders_supplier_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.purchase_orders
    ADD CONSTRAINT purchase_orders_supplier_id_foreign FOREIGN KEY (supplier_id) REFERENCES public.suppliers(id) ON DELETE SET NULL;


--
-- Name: security_logs security_logs_user_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.security_logs
    ADD CONSTRAINT security_logs_user_id_foreign FOREIGN KEY (user_id) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: subscription_invoices subscription_invoices_company_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.subscription_invoices
    ADD CONSTRAINT subscription_invoices_company_id_foreign FOREIGN KEY (company_id) REFERENCES public.companies(id) ON DELETE CASCADE;


--
-- Name: subscription_invoices subscription_invoices_subscription_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.subscription_invoices
    ADD CONSTRAINT subscription_invoices_subscription_id_foreign FOREIGN KEY (subscription_id) REFERENCES public.subscriptions(id) ON DELETE CASCADE;


--
-- Name: subscription_payments subscription_payments_subscription_invoice_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.subscription_payments
    ADD CONSTRAINT subscription_payments_subscription_invoice_id_foreign FOREIGN KEY (subscription_invoice_id) REFERENCES public.subscription_invoices(id) ON DELETE CASCADE;


--
-- Name: subscriptions subscriptions_company_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.subscriptions
    ADD CONSTRAINT subscriptions_company_id_foreign FOREIGN KEY (company_id) REFERENCES public.companies(id) ON DELETE CASCADE;


--
-- Name: subscriptions subscriptions_pricing_plan_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.subscriptions
    ADD CONSTRAINT subscriptions_pricing_plan_id_foreign FOREIGN KEY (pricing_plan_id) REFERENCES public.pricing_plans(id) ON DELETE CASCADE;


--
-- Name: suppliers suppliers_company_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.suppliers
    ADD CONSTRAINT suppliers_company_id_foreign FOREIGN KEY (company_id) REFERENCES public.companies(id) ON DELETE CASCADE;


--
-- Name: vendor_risk_profiles vendor_risk_profiles_company_id_foreign; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.vendor_risk_profiles
    ADD CONSTRAINT vendor_risk_profiles_company_id_foreign FOREIGN KEY (company_id) REFERENCES public.companies(id) ON DELETE CASCADE;


--
-- PostgreSQL database dump complete
--

\unrestrict D3XMBkUH5sdM7T4cDCcCb0Qs9xXlVEuEXhYhGsTZY8OE98FrXrkhmgcKy7Q2Nj5

