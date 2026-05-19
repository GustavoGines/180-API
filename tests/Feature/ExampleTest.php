<?php

test('test de ejemplo de feature', function () {
    $response = $this->get('/');

    $response->assertStatus(200);
});
