"""Static fictional data pools + prototype statutory parameters for seeding.

All names, numbers, and accounts here are FICTIONAL. Do not use real data.
Statutory parameters are ILLUSTRATIVE prototype values — not authoritative.
"""
from __future__ import annotations

FIRST_NAMES = [
    "Juan", "Maria", "Jose", "Anna", "Pedro", "Liza", "Mark", "Grace", "Paolo", "Nadine",
    "Miguel", "Sofia", "Carlo", "Bea", "Diego", "Ella", "Rafael", "Isabel", "Nico", "Camille",
    "Andres", "Trisha", "Emil", "Kaye", "Vince", "Hazel", "Renz", "Joy", "Kevin", "Mika",
    "Aldrin", "Faith", "Bryan", "Denise", "Ryan", "Patricia", "Erwin", "Angel", "Neil", "Cristina",
    "Gio", "Karla", "Lance", "Mavis", "Oscar", "Pia", "Quinn", "Rhea", "Sam", "Toni",
    "Ubaldo", "Vera", "Waldo", "Xandra", "Yuri", "Zeny", "Ben", "Cathy", "Dan", "Elaine",
    "Fritz", "Gemma", "Hans", "Iris", "Jerome", "Kris", "Lito", "Mimi",
]

LAST_NAMES = [
    "Santos", "Reyes", "Cruz", "Bautista", "Ocampo", "Garcia", "Mendoza", "Torres", "Flores", "Ramos",
    "Aquino", "Villanueva", "Castillo", "Domingo", "Fernandez", "Gonzales", "Hernandez", "Ignacio",
    "Jimenez", "Lim", "Manalo", "Navarro", "Padilla", "Quintos", "Rivera", "Salazar", "Tan",
    "Uy", "Valdez", "Yap", "Zamora", "Abad", "Bunag", "Carreon", "Dela Rosa", "Espino",
]

DEPARTMENTS = [
    "Human Resources", "Finance & Accounting", "Operations", "Information Technology",
    "Administration",
]

POSITIONS = [
    ("HR Officer", "P3"), ("Accountant", "P3"), ("Operations Associate", "P2"),
    ("Software Engineer", "P4"), ("Admin Assistant", "P1"), ("Project Coordinator", "P3"),
    ("Finance Manager", "M2"), ("HR Manager", "M2"), ("Senior Developer", "P5"),
    ("Field Technician", "P2"),
]

ORG_DEFS = [
    ("Exigent Corporation", "EXG"),
    ("Expedia Solutions Specialist Inc.", "ESS"),
    ("GreatnessLab", "GLB"),
    ("Kyrios Solutions Inc.", "KYR"),
]

PROJECT_DEFS = [
    ("PRJ-001", "Metro Manila Facilities Management"),
    ("PRJ-002", "Enterprise ERP Rollout"),
    ("PRJ-003", "Nationwide Field Survey"),
    ("PRJ-004", "Cloud Migration Program"),
    ("PRJ-005", "Retail Systems Support"),
]

# --- Prototype statutory parameters (ILLUSTRATIVE ONLY) ----------------------
STATUTORY_RULESETS = [
    {
        "rule_name": "SSS",
        "rule_version": "PROTO-2024.1",
        "parameters_json": {"employee_rate": 0.045, "msc_cap": 30000},
        "notes": "Prototype flat employee rate on capped MSC. Not authoritative.",
    },
    {
        "rule_name": "PHIC",
        "rule_version": "PROTO-2024.1",
        "parameters_json": {"employee_rate": 0.025, "salary_cap": 100000, "floor": 10000},
        "notes": "Prototype PhilHealth employee share. Not authoritative.",
    },
    {
        "rule_name": "HDMF",
        "rule_version": "PROTO-2024.1",
        "parameters_json": {"employee_rate": 0.02, "contribution_cap": 200},
        "notes": "Prototype Pag-IBIG employee share with cap. Not authoritative.",
    },
    {
        "rule_name": "BIR",
        "rule_version": "PROTO-2024.1",
        # Graduated monthly withholding [lower_bound, base_tax, marginal_rate].
        "parameters_json": {
            "brackets": [
                [0, 0.0, 0.0],
                [20833, 0.0, 0.15],
                [33333, 1875.0, 0.20],
                [66667, 8541.80, 0.25],
                [166667, 33541.80, 0.30],
                [666667, 183541.80, 0.35],
            ]
        },
        "notes": "Prototype graduated monthly withholding. Not authoritative.",
    },
]
