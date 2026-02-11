<h2>Create Invoice</h2>

<form method="POST" action="/invoice/store">
    @csrf

    Buyer Name:
    <input type="text" name="buyer_name"><br>

    Buyer NTN:
    <input type="text" name="buyer_ntn"><br>

    Total Amount:
    <input type="number" step="0.01" name="total_amount"><br>

    <h4>Item Details</h4>

    HS Code:
    <input type="text" name="hs_code"><br>

    Description:
    <input type="text" name="description"><br>

    Quantity:
    <input type="number" step="0.01" name="quantity"><br>

    Price:
    <input type="number" step="0.01" name="price"><br>

    Tax:
    <input type="number" step="0.01" name="tax"><br>

    <button type="submit">Save Invoice</button>
</form>
