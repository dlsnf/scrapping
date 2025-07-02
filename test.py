#!/usr/bin/env python3
# -*- coding: utf-8 -*-

import json

def get_json_data():
    # Define variables
    company_name = "Company Name"
    total_chargers = 50
    used_chargers = 30
    remaining_chargers = total_chargers - used_chargers
    charger_type = "AC"
    address = "Seoul, Gangnam-gu"

    # Create a dictionary with English keys
    data = {
        "companyName": company_name,
        "totalChargers": total_chargers,
        "usedChargers": used_chargers,
        "remainingChargers": remaining_chargers,
        "chargerType": charger_type,
        "address": address
    }

    # Convert the dictionary to a JSON string
    json_data = json.dumps(data)
    return json_data

# Example usage: print the JSON data
print(get_json_data())
