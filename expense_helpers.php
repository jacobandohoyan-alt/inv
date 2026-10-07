<?php
// Fixed lists used by the Suppliers & Expenses page (add a line to add an option)

function expenseCategories(): array
{
    return [
        "Rent",
        "Utilities",
        "Salaries & Wages",
        "Supplies",
        "Transportation",
        "Maintenance",
        "Marketing",
        "Permits & Licenses",
        "Others",
    ];
}

function paymentMethods(): array
{
    return ["Cash", "GCash", "Bank Transfer", "Other"];
}