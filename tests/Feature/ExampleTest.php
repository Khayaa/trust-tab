<?php

test('the application sends visitors to their tabs', function () {
    $response = $this->get('/');

    $response->assertRedirect('/tabs');
});
