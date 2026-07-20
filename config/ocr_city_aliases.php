<?php

/**
 * OCR directory city aliases and approved heading rules.
 * Display city uses canonical form; raw heading is preserved separately.
 */
return [

    /*
    | Reviewed OCR aliases → canonical display city (uppercase).
    */
    'aliases' => [
        'ahily nagar' => 'AHILYANAGAR',
        'ahilya nagar' => 'AHILYANAGAR',
        'ahilyanagar' => 'AHILYANAGAR',
        'ahmed nagar' => 'AHMEDNAGAR',
        'ahmednagar' => 'AHMEDNAGAR',
        'new delhi' => 'NEW DELHI',
        'delhi' => 'DELHI',
        'bengaluru' => 'BENGALURU',
        'bangalore' => 'BENGALURU',
        'mumbai' => 'MUMBAI',
        'bombay' => 'MUMBAI',
        'kolkata' => 'KOLKATA',
        'calcutta' => 'KOLKATA',
        'chennai' => 'CHENNAI',
        'madras' => 'CHENNAI',
        'hyderabad' => 'HYDERABAD',
        'pune' => 'PUNE',
        'abu road' => 'ABU ROAD',
        'aburoad' => 'ABU ROAD',
        'ambala city' => 'AMBALA',
        'ambala cantt' => 'AMBALA CANTT',
    ],

    /*
    | Multi-word * ROAD headings accepted as ICAI section cities only.
    | Street lines like PATEL ROAD / CIRCULAR ROAD must NOT appear here.
    */
    'approved_road_cities' => [
        'abu road',
    ],

    /*
    | ICAI directory cities commonly missing from CRM city master.
    | Used for heading acceptance when Master lookup fails.
    */
    'directory_cities' => [
        'abohar', 'adipur', 'ahilyanagar', 'ahmednagar', 'ahmedabad', 'ajmer', 'akola',
        'aligarh', 'allahabad', 'ambala', 'ambala cantt', 'amritsar', 'anand', 'asansol',
        'aurangabad', 'bareilly', 'barrackpore', 'bathinda', 'belgaum', 'bengaluru',
        'bhatpara', 'bhavnagar', 'bhopal', 'bhubaneswar', 'bikaner', 'bilaspur',
        'chandigarh', 'chennai', 'coimbatore', 'cuttack', 'dehradun', 'delhi', 'new delhi',
        'dhanbad', 'durgapur', 'faridabad', 'firozpur', 'gandhinagar', 'ghaziabad',
        'gorakhpur', 'gulbarga', 'gurgaon', 'gurugram', 'guwahati', 'gwalior', 'hisar',
        'howrah', 'hubli', 'hyderabad', 'indore', 'jabalpur', 'jaipur', 'jalandhar',
        'jalgaon', 'jammu', 'jamnagar', 'jamshedpur', 'jhansi', 'jodhpur', 'kanpur',
        'karnal', 'kochi', 'kolhapur', 'kolkata', 'kota', 'kozhikode', 'lucknow',
        'ludhiana', 'madurai', 'mangalore', 'meerut', 'mohali', 'moradabad', 'mumbai',
        'mysore', 'nagpur', 'nashik', 'navi mumbai', 'noida', 'patna', 'panipat',
        'patiala', 'pondicherry', 'pune', 'raipur', 'rajkot', 'ranchi', 'rohtak',
        'salem', 'sangli', 'satara', 'shimla', 'siliguri', 'solapur', 'sonipat',
        'srinagar', 'surat', 'thane', 'thiruvananthapuram', 'thrissur', 'tiruchirappalli',
        'trichy', 'udaipur', 'ujjain', 'vadodara', 'varanasi', 'vijayawada', 'visakhapatnam',
        'warangal', 'abhanpur', 'abu road', 'supaul', 'sangamner', 'savedi',
        'abhoynagar', 'dakshineswar',
        'siddhiashram', 'agartala', 'silchar', 'murshidabad', 'jagatsinghpur',
        'naraingarh', 'ahmedgarh',
    ],
];
