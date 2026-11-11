<?php

return [
    'accepted' => ':attribute kabul edilmelidir.',
    'after' => ':attribute, :date tarihinden sonra olmalıdır.',
    'after_or_equal' => ':attribute, :date tarihi veya sonrası olmalıdır.',
    'array' => ':attribute bir liste olmalıdır.',
    'before' => ':attribute, :date tarihinden önce olmalıdır.',
    'before_or_equal' => ':attribute, :date tarihi veya öncesi olmalıdır.',
    'between' => [
        'numeric' => ':attribute :min ile :max arasında olmalıdır.',
        'string' => ':attribute :min ile :max karakter arasında olmalıdır.',
    ],
    'boolean' => ':attribute alanı evet ya da hayır olmalıdır.',
    'confirmed' => ':attribute tekrarı eşleşmiyor.',
    'date' => ':attribute geçerli bir tarih olmalıdır.',
    'date_format' => ':attribute :format biçiminde olmalıdır.',
    'decimal' => ':attribute en fazla :decimal ondalık basamak içermelidir.',
    'different' => ':attribute ile :other farklı olmalıdır.',
    'digits' => ':attribute :digits haneli olmalıdır.',
    'email' => ':attribute geçerli bir e-posta adresi olmalıdır.',
    'enum' => 'Seçilen :attribute geçersiz.',
    'exists' => 'Seçilen :attribute geçersiz.',
    'gt' => [
        'numeric' => ':attribute :value değerinden büyük olmalıdır.',
    ],
    'gte' => [
        'numeric' => ':attribute :value veya daha büyük olmalıdır.',
    ],
    'in' => 'Seçilen :attribute geçersiz.',
    'integer' => ':attribute tam sayı olmalıdır.',
    'lte' => [
        'numeric' => ':attribute :value veya daha küçük olmalıdır.',
    ],
    'max' => [
        'numeric' => ':attribute :max değerinden büyük olamaz.',
        'string' => ':attribute en fazla :max karakter olabilir.',
    ],
    'min' => [
        'numeric' => ':attribute en az :min olmalıdır.',
        'string' => ':attribute en az :min karakter olmalıdır.',
    ],
    'numeric' => ':attribute sayı olmalıdır.',
    'password' => [
        'letters' => ':attribute en az bir harf içermelidir.',
        'mixed' => ':attribute en az bir büyük ve bir küçük harf içermelidir.',
        'numbers' => ':attribute en az bir rakam içermelidir.',
        'symbols' => ':attribute en az bir sembol içermelidir.',
        'uncompromised' => 'Bu :attribute bir veri sızıntısında görülmüş. Farklı bir :attribute seçin.',
    ],
    'regex' => ':attribute biçimi geçersiz.',
    'required' => ':attribute alanı zorunludur.',
    'required_if' => ':other :value olduğunda :attribute zorunludur.',
    'same' => ':attribute ile :other eşleşmelidir.',
    'size' => [
        'string' => ':attribute :size karakter olmalıdır.',
    ],
    'string' => ':attribute metin olmalıdır.',
    'unique' => 'Bu :attribute zaten kullanılıyor.',

    'attributes' => [
        'name' => 'Ad soyad',
        'username' => 'Kullanıcı adı',
        'email' => 'E-posta',
        'password' => 'Parola',
        'password_confirmation' => 'Parola tekrarı',
        'role' => 'Rol',
        'is_active' => 'Durum',
        'q' => 'Arama',
        'status' => 'Durum',
        'sort' => 'Sıralama',
        'dir' => 'Sıralama yönü',
    ],
];
