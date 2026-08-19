<?php
// app/ai/providers/GroqProvider.php

class GroqProvider {
    private $apiKey;
    private $model = 'llama3-70b-8192'; // Fast, free model
    
    public function __construct() {
        $this->apiKey = defined('GROQ_API_KEY') ? GROQ_API_KEY : '';
    }
    
    public function generate($prompt, $format = 'json') {
        if (empty($this->apiKey)) {
            return null;
        }
        
        $url = "https://api.groq.com/openai/v1/chat/completions";
        
        $data = [
            'model' => $this->model,
            'messages' => [
                ['role' => 'system', 'content' => 'You are an expert HR assistant. Respond with valid JSON.'],
                ['role' => 'user', 'content' => $prompt]
            ],
            'temperature' => 0.7,
            'max_tokens' => 1000,
            'response_format' => ['type' => 'json_object']
        ];
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $this->apiKey,
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200) {
            $result = json_decode($response, true);
            $content = $result['choices'][0]['message']['content'] ?? '';
            
            if ($format === 'json') {
                return json_decode($content, true);
            }
            return $content;
        }
        
        return null;
    }
}