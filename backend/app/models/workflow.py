"""Generic, configurable approval-workflow engine (Phase 2).

A workflow is a named, ordered sequence of steps scoped by organization and
transaction type (leave, OT, cash advance, loan, payroll, etc.). Instances track
a specific request through its steps; actions record each approve/reject.
"""
from __future__ import annotations

from sqlalchemy import ForeignKey, Integer, JSON, Numeric, String, Text
from sqlalchemy.orm import Mapped, mapped_column, relationship

from app.core.database import Base
from app.models.base import TimestampMixin, UUIDMixin


class ApprovalWorkflow(Base, TimestampMixin):
    __tablename__ = "approval_workflows"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    organization_id: Mapped[int] = mapped_column(ForeignKey("organizations.id"), index=True, nullable=False)
    name: Mapped[str] = mapped_column(String(120), nullable=False)
    transaction_type: Mapped[str] = mapped_column(String(40), index=True, nullable=False)
    # Optional conditions: {min_amount, department, employee_type, project}
    conditions_json: Mapped[dict | None] = mapped_column(JSON, nullable=True)
    active: Mapped[bool] = mapped_column(default=True)

    steps: Mapped[list["ApprovalWorkflowStep"]] = relationship(
        back_populates="workflow", order_by="ApprovalWorkflowStep.step_order", lazy="selectin"
    )


class ApprovalWorkflowStep(Base, TimestampMixin):
    __tablename__ = "approval_workflow_steps"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    workflow_id: Mapped[int] = mapped_column(ForeignKey("approval_workflows.id"), index=True, nullable=False)
    step_order: Mapped[int] = mapped_column(Integer, default=1)
    name: Mapped[str] = mapped_column(String(80), nullable=False)  # Supervisor / Dept Head / HR
    approver_role: Mapped[str | None] = mapped_column(String(80), nullable=True)

    workflow: Mapped["ApprovalWorkflow"] = relationship(back_populates="steps")


class ApprovalInstance(Base, UUIDMixin, TimestampMixin):
    __tablename__ = "approval_instances"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    organization_id: Mapped[int] = mapped_column(ForeignKey("organizations.id"), index=True, nullable=False)
    workflow_id: Mapped[int | None] = mapped_column(ForeignKey("approval_workflows.id"), nullable=True)
    transaction_type: Mapped[str] = mapped_column(String(40), index=True, nullable=False)
    entity: Mapped[str] = mapped_column(String(60), nullable=False)      # e.g. leave_request
    entity_id: Mapped[int] = mapped_column(Integer, nullable=False)
    amount: Mapped[float | None] = mapped_column(Numeric(14, 2), nullable=True)
    current_step: Mapped[int] = mapped_column(Integer, default=1)
    status: Mapped[str] = mapped_column(String(20), default="PENDING", index=True)  # PENDING/APPROVED/REJECTED

    actions: Mapped[list["ApprovalAction"]] = relationship(back_populates="instance", lazy="selectin")


class ApprovalAction(Base, TimestampMixin):
    __tablename__ = "approval_actions"

    id: Mapped[int] = mapped_column(Integer, primary_key=True)
    instance_id: Mapped[int] = mapped_column(ForeignKey("approval_instances.id"), index=True, nullable=False)
    step_order: Mapped[int] = mapped_column(Integer, default=1)
    action: Mapped[str] = mapped_column(String(20), nullable=False)  # APPROVE/REJECT
    actor_user_id: Mapped[int | None] = mapped_column(ForeignKey("users.id"), nullable=True)
    remarks: Mapped[str | None] = mapped_column(Text, nullable=True)

    instance: Mapped["ApprovalInstance"] = relationship(back_populates="actions")
