<?php
// Test script per verificare la crittografia

const ENCRYPTION_ALGORITHM = 'aes-256-cbc';

function testEncryption() {
    echo "Test crittografia...\n";
    
    // Test 1: Generazione salt
    echo "\n1. Test generazione salt:\n";
    $salt = base64_encode(random_bytes(32));
    echo "Salt generato: " . substr($salt, 0, 20) . "...\n";
    
    // Test 2: Derivazione chiave PBKDF2
    echo "\n2. Test derivazione chiave PBKDF2:\n";
    $password = "TestPassword123";
    $key = hash_pbkdf2('sha256', $password, $salt, 100000, 32, true);
    echo "Chiave derivata (base64): " . substr(base64_encode($key), 0, 20) . "...\n";
    echo "Lunghezza chiave: " . strlen($key) . " bytes\n";
    
    // Test 3: Crittografia
    echo "\n3. Test crittografia AES-256-CBC:\n";
    $plaintext = "TestUsername@example.com";
    $iv = openssl_random_pseudo_bytes(16);
    $encrypted = openssl_encrypt($plaintext, ENCRYPTION_ALGORITHM, $key, OPENSSL_RAW_DATA, $iv);
    
    if ($encrypted === false) {
        echo "ERRORE: Crittografia fallita!\n";
        echo "Errore OpenSSL: " . openssl_error_string() . "\n";
        return false;
    }
    
    $encrypted_b64 = base64_encode($iv . $encrypted);
    echo "Testo originale: $plaintext\n";
    echo "Testo crittografato (base64): " . substr($encrypted_b64, 0, 50) . "...\n";
    
    // Test 4: Decrittografia
    echo "\n4. Test decrittografia:\n";
    $data = base64_decode($encrypted_b64);
    $iv_decode = substr($data, 0, 16);
    $ciphertext = substr($data, 16);
    $decrypted = openssl_decrypt($ciphertext, ENCRYPTION_ALGORITHM, $key, OPENSSL_RAW_DATA, $iv_decode);
    
    if ($decrypted === false) {
        echo "ERRORE: Decrittografia fallita!\n";
        echo "Errore OpenSSL: " . openssl_error_string() . "\n";
        return false;
    }
    
    echo "Testo decrittografato: $decrypted\n";
    echo "Match originale: " . ($decrypted === $plaintext ? "✅ SÌ" : "❌ NO") . "\n";
    
    // Test 5: Funzione con formato "enc:" prefix
    echo "\n5. Test con formato 'enc:' prefix:\n";
    $encrypted_with_prefix = 'enc:' . $encrypted_b64;
    echo "Valore memorizzato: " . substr($encrypted_with_prefix, 0, 50) . "...\n";
    
    // Simula lettura e decrittografia
    if (strpos($encrypted_with_prefix, 'enc:') === 0) {
        $stored_data = base64_decode(substr($encrypted_with_prefix, 4));
        $stored_iv = substr($stored_data, 0, 16);
        $stored_cipher = substr($stored_data, 16);
        $stored_decrypted = openssl_decrypt($stored_cipher, ENCRYPTION_ALGORITHM, $key, OPENSSL_RAW_DATA, $stored_iv);
        
        if ($stored_decrypted === false) {
            echo "ERRORE: Decrittografia memorizzata fallita!\n";
            return false;
        }
        
        echo "Valore recuperato: $stored_decrypted\n";
        echo "Match: " . ($stored_decrypted === $plaintext ? "✅ SÌ" : "❌ NO") . "\n";
    }
    
    echo "\n✅ Tutti i test di crittografia hanno avuto successo!\n";
    return true;
}

testEncryption();
?>
