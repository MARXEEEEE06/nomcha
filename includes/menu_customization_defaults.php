<?php
function menuCustomizationDefaults(string $name): array {
	if (in_array($name, ['NTS Bonless Chikin', 'NTS Bonless Chikin & Rosébokki'], true)) return [['key'=>'glaze','label'=>'Choose a glaze','required'=>true,'choices'=>array_map(static fn($value)=>['name'=>$value,'priceCents'=>0],['Soy Garlic','Spicy','BYN','Honey Citrus','Crunchy Garlic','Golden Crunch','Snow Cheese'])]];
	if (strcasecmp($name,'Chapchae')===0) return [['key'=>'spiceLevel','label'=>'Choose your spice level','required'=>true,'choices'=>array_map(static fn($value)=>['name'=>$value,'priceCents'=>0],['Regular','Mild Spicy','Spicy'])]];
	$chicken=preg_match('/chicken|chikin|bonless|chix|bap bowl|poppers|sandwich|fish & chapchae|good for/i',$name)===1;
	$meal=preg_match('/meal|rice|bibimbowl|bap bowl|poppers|sandwich|snackwich|beef|chix|pork bbq|fish & chapchae|good for|fish n\s*[\'’] chix/i',$name)===1;
	if ((!$chicken&&!$meal)||preg_match('/Fanverse Meal$/',$name)) return [];
	$flavors=['Soy Garlic','Spicy','BYN','Honey Citrus','Crunchy Garlic','YangNyeom','Snow Cheese','Golden Crunch'];
	$groups=$chicken?[['key'=>'flavor','label'=>'Choice of Chicken Flavors','required'=>true,'choices'=>array_map(static fn($v)=>['name'=>$v,'priceCents'=>0],$flavors)]]:[];
	if ($meal) {
		$groups[]=['key'=>'sides','label'=>'Choice of Sides','required'=>false,'multiple'=>true,'maxCount'=>9,'choices'=>[['name'=>'Cheeseballs - Plain w/ Pizza Sauce (1pc)','priceCents'=>4700],['name'=>'Cheeseballs - Snowcheese (1pc)','priceCents'=>4700],['name'=>'Kimchi','priceCents'=>4700]]];
		$groups[]=['key'=>'drink','label'=>'Choice of Drink','required'=>true,'choices'=>[['name'=>'Regular Iced Tea','priceCents'=>0],['name'=>'Large Iced Tea','priceCents'=>4000],['name'=>'Caramel Snow Ice Coffee Drink Upgrade','priceCents'=>9500],['name'=>'Chocolate Snow Ice Coffee Drink Upgrade','priceCents'=>9500]]];
		$groups[]=['key'=>'riceUpgrade','label'=>'Upgrade to Kimchi Rice','required'=>false,'choices'=>[['name'=>'Kimchi Rice Upgrade','priceCents'=>5100]]];
		$groups[]=['key'=>'soup','label'=>'Add-ons','required'=>false,'choices'=>[['name'=>'Add on Bulgogi Soup','priceCents'=>6800]]];
	}
	return $groups;
}
