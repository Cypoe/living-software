-- Living Software kernel schema (initial minimal draft)

create table if not exists entities (
  id text primary key,
  type text not null,
  owner text,
  body_ref text,
  metadata_json text default '{}',
  created_at text default current_timestamp,
  updated_at text default current_timestamp
);

create table if not exists schemas (
  id text primary key,
  name text not null,
  definition_json text not null,
  created_at text default current_timestamp
);

create table if not exists carriers (
  id text primary key,
  entity_id text,
  carrier_type text not null,
  locator text,
  content_hash text,
  metadata_json text default '{}',
  created_at text default current_timestamp,
  foreign key(entity_id) references entities(id)
);

create table if not exists capabilities (
  id text primary key,
  schema_in text not null,
  schema_out text not null,
  impl_type text not null,
  contract_json text default '{}',
  impl_ref text not null,
  created_at text default current_timestamp,
  foreign key(schema_in) references schemas(id),
  foreign key(schema_out) references schemas(id)
);

create table if not exists applications (
  id integer primary key autoincrement,
  capability_id text not null,
  input_ref text,
  output_ref text,
  result text,
  ancestry_ref text,
  created_at text default current_timestamp,
  foreign key(capability_id) references capabilities(id)
);

create table if not exists runtime_state (
  key text primary key,
  value text not null,
  updated_at text default current_timestamp
);
