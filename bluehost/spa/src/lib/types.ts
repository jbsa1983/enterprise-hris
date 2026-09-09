export interface OrgMembership {
  organization_id: number;
  name: string;
  code: string;
  is_primary: boolean;
}

export interface CurrentUser {
  id: number;
  uuid: string;
  email: string;
  full_name: string;
  is_superadmin: boolean;
  roles: string[];
  permissions: string[];
  organizations: OrgMembership[];
}

export interface EngagementRow {
  engagement_id: number;
  person_id: number;
  full_name: string;
  engagement_type: string;
  employee_number: string | null;
  status: string;
  base_rate: number | null;
  start_date: string | null;
  end_date: string | null;
}

export interface StorageOverview {
  used_gb: number;
  available_gb: number;
  total_gb: number;
  percent_used: number;
  status: string;
  breakdown: Record<string, number>;
  refresh_seconds: number;
  simulated_capacity: boolean;
  by_organization: { organization_id: number; used_gb: number }[];
}
