<?php
// Payout profile fields per user type. Generated from database/profile-fields.csv
return array (
  'self' => 
  array (
    'label' => 'Самозанятый',
    'section' => 'Данные: ИП | Самозанятого лица',
    'fields' => 
    array (
      0 => 
      array (
        'id' => 27,
        'label' => 'Фамилия',
        'type' => 'text',
        'required' => true,
      ),
      1 => 
      array (
        'id' => 28,
        'label' => 'Имя',
        'type' => 'text',
        'required' => true,
      ),
      2 => 
      array (
        'id' => 29,
        'label' => 'Отчество',
        'type' => 'text',
        'required' => true,
      ),
      3 => 
      array (
        'id' => 30,
        'label' => 'Дата рождения',
        'type' => 'date',
        'required' => true,
      ),
      4 => 
      array (
        'id' => 31,
        'label' => 'Номер паспорта',
        'type' => 'text',
        'required' => true,
      ),
      5 => 
      array (
        'id' => 32,
        'label' => 'ИНН',
        'type' => 'text',
        'required' => true,
      ),
      6 => 
      array (
        'id' => 33,
        'label' => 'ОГРНИП',
        'type' => 'text',
        'required' => false,
      ),
      7 => 
      array (
        'id' => 34,
        'label' => 'Дата госрегистрации',
        'type' => 'date',
        'required' => true,
      ),
      8 => 
      array (
        'id' => 35,
        'label' => 'Адрес регистрации',
        'type' => 'text',
        'required' => true,
      ),
      9 => 
      array (
        'id' => 36,
        'label' => 'Название банка',
        'type' => 'text',
        'required' => true,
      ),
      10 => 
      array (
        'id' => 37,
        'label' => 'БИК банка',
        'type' => 'text',
        'required' => true,
      ),
      11 => 
      array (
        'id' => 38,
        'label' => 'Корреспондентский счёт',
        'type' => 'text',
        'required' => true,
      ),
      12 => 
      array (
        'id' => 39,
        'label' => 'Расчётный счёт',
        'type' => 'text',
        'required' => true,
      ),
      13 => 
      array (
        'id' => 40,
        'label' => ' ФИО контактного лица',
        'type' => 'text',
        'required' => true,
      ),
      14 => 
      array (
        'id' => 41,
        'label' => 'Тел. контактного лица',
        'type' => 'text',
        'required' => true,
      ),
      15 => 
      array (
        'id' => 42,
        'label' => 'Э-маил контактного лица',
        'type' => 'text',
        'required' => true,
      ),
      16 => 
      array (
        'id' => 48,
        'label' => 'Номер телефона для денежного перевода',
        'type' => 'text',
        'required' => true,
      ),
      17 => 
      array (
        'id' => 49,
        'label' => 'Банк получателя',
        'type' => 'text',
        'required' => true,
      ),
    ),
  ),
  'company' => 
  array (
    'label' => 'Компания',
    'section' => 'Данные: Юридического лица',
    'fields' => 
    array (
      0 => 
      array (
        'id' => 2,
        'label' => 'Краткое наименование',
        'type' => 'text',
        'required' => true,
      ),
      1 => 
      array (
        'id' => 10,
        'label' => 'Полное наименование организации',
        'type' => 'text',
        'required' => true,
      ),
      2 => 
      array (
        'id' => 11,
        'label' => 'ОГРН',
        'type' => 'text',
        'required' => true,
      ),
      3 => 
      array (
        'id' => 12,
        'label' => 'ИНН организации',
        'type' => 'text',
        'required' => true,
      ),
      4 => 
      array (
        'id' => 13,
        'label' => 'КПП',
        'type' => 'text',
        'required' => true,
      ),
      5 => 
      array (
        'id' => 14,
        'label' => 'Юридический адрес',
        'type' => 'text',
        'required' => true,
      ),
      6 => 
      array (
        'id' => 16,
        'label' => 'Название банка',
        'type' => 'text',
        'required' => true,
      ),
      7 => 
      array (
        'id' => 17,
        'label' => 'БИК банка',
        'type' => 'text',
        'required' => true,
      ),
      8 => 
      array (
        'id' => 18,
        'label' => 'Корреспондентский счёт',
        'type' => 'text',
        'required' => true,
      ),
      9 => 
      array (
        'id' => 19,
        'label' => 'Расчётный счёт',
        'type' => 'text',
        'required' => true,
      ),
      10 => 
      array (
        'id' => 20,
        'label' => 'Должность руководителя',
        'type' => 'text',
        'required' => true,
      ),
      11 => 
      array (
        'id' => 21,
        'label' => 'Фамилия руководителя',
        'type' => 'text',
        'required' => true,
      ),
      12 => 
      array (
        'id' => 22,
        'label' => 'Имя  руководителя',
        'type' => 'text',
        'required' => true,
      ),
      13 => 
      array (
        'id' => 23,
        'label' => 'Отчество руководителя',
        'type' => 'text',
        'required' => true,
      ),
      14 => 
      array (
        'id' => 24,
        'label' => ' ФИО контактного лица',
        'type' => 'text',
        'required' => false,
      ),
      15 => 
      array (
        'id' => 25,
        'label' => 'Тел. контактного лица',
        'type' => 'text',
        'required' => true,
      ),
      16 => 
      array (
        'id' => 26,
        'label' => 'Э-маил контактного лица',
        'type' => 'text',
        'required' => true,
      ),
    ),
  ),
  'personal' => 
  array (
    'label' => 'Физлицо',
    'section' => 'Данные: Физлицо',
    'fields' => 
    array (
      0 => 
      array (
        'id' => 1,
        'label' => 'Фамилия',
        'type' => 'text',
        'required' => true,
      ),
      1 => 
      array (
        'id' => 3,
        'label' => 'Имя',
        'type' => 'text',
        'required' => true,
      ),
      2 => 
      array (
        'id' => 4,
        'label' => 'Отчество',
        'type' => 'text',
        'required' => true,
      ),
      3 => 
      array (
        'id' => 5,
        'label' => 'Дата рождения',
        'type' => 'date',
        'required' => true,
      ),
      4 => 
      array (
        'id' => 6,
        'label' => 'Паспорт',
        'type' => 'text',
        'required' => true,
      ),
      5 => 
      array (
        'id' => 7,
        'label' => 'ИНН',
        'type' => 'number',
        'required' => true,
      ),
      6 => 
      array (
        'id' => 8,
        'label' => 'СНИЛС',
        'type' => 'text',
        'required' => false,
      ),
      7 => 
      array (
        'id' => 9,
        'label' => 'Адрес регистрации',
        'type' => 'text',
        'required' => true,
      ),
      8 => 
      array (
        'id' => 15,
        'label' => 'Фактический адрес',
        'type' => 'text',
        'required' => false,
      ),
      9 => 
      array (
        'id' => 43,
        'label' => 'Номер телефона для денежного перевода',
        'type' => 'text',
        'required' => true,
      ),
      10 => 
      array (
        'id' => 47,
        'label' => 'Банк получателя',
        'type' => 'text',
        'required' => true,
      ),
    ),
  ),
);
