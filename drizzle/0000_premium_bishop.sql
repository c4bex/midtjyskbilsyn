CREATE TABLE `audit_events` (
	`id` text PRIMARY KEY NOT NULL,
	`occurred_at` integer NOT NULL,
	`actor_type` text NOT NULL,
	`actor_id` text,
	`action` text NOT NULL,
	`entity_type` text NOT NULL,
	`entity_id` text NOT NULL,
	`correlation_id` text NOT NULL,
	`before_json` text,
	`after_json` text,
	`metadata_json` text
);
--> statement-breakpoint
CREATE INDEX `idx_audit_entity` ON `audit_events` (`entity_type`,`entity_id`,`occurred_at`);--> statement-breakpoint
CREATE INDEX `idx_audit_correlation` ON `audit_events` (`correlation_id`);--> statement-breakpoint
CREATE TABLE `availability_rules` (
	`id` text PRIMARY KEY NOT NULL,
	`station_id` text NOT NULL,
	`kind` text NOT NULL,
	`weekday` integer,
	`starts_at` text,
	`ends_at` text,
	`date_from` text,
	`date_to` text,
	`label` text NOT NULL,
	`created_at` integer NOT NULL,
	`updated_at` integer NOT NULL,
	FOREIGN KEY (`station_id`) REFERENCES `stations`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE INDEX `idx_availability_station_kind` ON `availability_rules` (`station_id`,`kind`);--> statement-breakpoint
CREATE TABLE `bookings` (
	`id` text PRIMARY KEY NOT NULL,
	`station_id` text NOT NULL,
	`customer_id` text,
	`vehicle_id` text NOT NULL,
	`assigned_employee_id` text,
	`starts_at` integer NOT NULL,
	`ends_at` integer NOT NULL,
	`inspection_type` text NOT NULL,
	`status` text NOT NULL,
	`source` text DEFAULT 'manual' NOT NULL,
	`source_reference` text,
	`internal_note` text,
	`created_at` integer NOT NULL,
	`updated_at` integer NOT NULL,
	FOREIGN KEY (`station_id`) REFERENCES `stations`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`assigned_employee_id`) REFERENCES `employees`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE INDEX `idx_bookings_station_starts_at` ON `bookings` (`station_id`,`starts_at`);--> statement-breakpoint
CREATE INDEX `idx_bookings_vehicle_starts_at` ON `bookings` (`vehicle_id`,`starts_at`);--> statement-breakpoint
CREATE UNIQUE INDEX `uidx_bookings_source_reference` ON `bookings` (`source`,`source_reference`);--> statement-breakpoint
CREATE TABLE `customers` (
	`id` text PRIMARY KEY NOT NULL,
	`display_name` text NOT NULL,
	`customer_type` text NOT NULL,
	`phone_encrypted` text,
	`email_encrypted` text,
	`external_reference` text,
	`deleted_at` integer,
	`created_at` integer NOT NULL,
	`updated_at` integer NOT NULL
);
--> statement-breakpoint
CREATE TABLE `employees` (
	`id` text PRIMARY KEY NOT NULL,
	`station_id` text NOT NULL,
	`display_name` text NOT NULL,
	`email_normalized` text NOT NULL,
	`role` text NOT NULL,
	`active` integer DEFAULT true NOT NULL,
	`created_at` integer NOT NULL,
	`updated_at` integer NOT NULL,
	FOREIGN KEY (`station_id`) REFERENCES `stations`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE UNIQUE INDEX `uidx_employees_email` ON `employees` (`email_normalized`);--> statement-breakpoint
CREATE INDEX `idx_employees_station_active` ON `employees` (`station_id`,`active`);--> statement-breakpoint
CREATE TABLE `integration_jobs` (
	`id` text PRIMARY KEY NOT NULL,
	`adapter` text NOT NULL,
	`operation` text NOT NULL,
	`aggregate_type` text NOT NULL,
	`aggregate_id` text NOT NULL,
	`idempotency_key` text NOT NULL,
	`payload_json` text NOT NULL,
	`status` text NOT NULL,
	`attempts` integer DEFAULT 0 NOT NULL,
	`next_attempt_at` integer,
	`last_error_code` text,
	`last_error_summary` text,
	`created_at` integer NOT NULL,
	`updated_at` integer NOT NULL
);
--> statement-breakpoint
CREATE UNIQUE INDEX `uidx_integration_jobs_idempotency` ON `integration_jobs` (`idempotency_key`);--> statement-breakpoint
CREATE INDEX `idx_integration_jobs_status_next_attempt` ON `integration_jobs` (`status`,`next_attempt_at`);--> statement-breakpoint
CREATE TABLE `invoices` (
	`id` text PRIMARY KEY NOT NULL,
	`booking_id` text NOT NULL,
	`status` text NOT NULL,
	`amount_oere` integer NOT NULL,
	`currency` text DEFAULT 'DKK' NOT NULL,
	`dinero_invoice_id` text,
	`idempotency_key` text NOT NULL,
	`invoiced_at` integer,
	`created_at` integer NOT NULL,
	`updated_at` integer NOT NULL,
	FOREIGN KEY (`booking_id`) REFERENCES `bookings`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE UNIQUE INDEX `uidx_invoices_booking` ON `invoices` (`booking_id`);--> statement-breakpoint
CREATE UNIQUE INDEX `uidx_invoices_idempotency` ON `invoices` (`idempotency_key`);--> statement-breakpoint
CREATE UNIQUE INDEX `uidx_invoices_dinero_id` ON `invoices` (`dinero_invoice_id`);--> statement-breakpoint
CREATE TABLE `stations` (
	`id` text PRIMARY KEY NOT NULL,
	`name` text NOT NULL,
	`timezone` text DEFAULT 'Europe/Copenhagen' NOT NULL,
	`active` integer DEFAULT true NOT NULL,
	`created_at` integer NOT NULL,
	`updated_at` integer NOT NULL
);
--> statement-breakpoint
CREATE TABLE `vehicles` (
	`id` text PRIMARY KEY NOT NULL,
	`customer_id` text,
	`registration_normalized` text NOT NULL,
	`make` text,
	`model` text,
	`vehicle_kind` text,
	`created_at` integer NOT NULL,
	`updated_at` integer NOT NULL,
	FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE UNIQUE INDEX `uidx_vehicles_registration` ON `vehicles` (`registration_normalized`);