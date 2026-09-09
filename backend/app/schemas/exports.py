from __future__ import annotations

from typing import Any, Optional

from pydantic import BaseModel


class BankColumnIn(BaseModel):
    system_field: str
    output_header: str
    order_index: int = 0
    default_value: Optional[str] = None
    formatting: Optional[str] = None
    padding: Optional[str] = None
    required: bool = False


class BankTemplateIn(BaseModel):
    template_name: str
    bank_name: str
    file_type: str = "CSV"
    delimiter: str = ","
    encoding: str = "utf-8"
    header_required: bool = True
    footer_required: bool = False
    date_format: str = "%Y-%m-%d"
    decimal_places: int = 2
    filename_pattern: str = "{bank}_{org}_{date}"
    columns: list[BankColumnIn] = []


class BankExportRequest(BaseModel):
    template_id: int
    filters: dict[str, Any] = {}
    allow_with_errors: bool = False


class ReportRequest(BaseModel):
    dataset: str
    config: dict[str, Any] = {}


class ReportTemplateIn(BaseModel):
    name: str
    dataset: str
    config: dict[str, Any] = {}
